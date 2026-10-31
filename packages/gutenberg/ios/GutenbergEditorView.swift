// Copyright (C) 2026 Chris Huber
// SPDX-License-Identifier: MIT

import ExpoModulesCore
import GutenbergKit
import UIKit

private struct WPNativeEditorNotReadyError: LocalizedError {
  var errorDescription: String? {
    "The Gutenberg editor is not ready. Wait for onReady before requesting content."
  }
}

@MainActor
final class GutenbergEditorView: ExpoView, EditorViewControllerDelegate, @unchecked Sendable {
  let onReady = EventDispatcher()
  let onError = EventDispatcher()

  var initialTitle = ""
  var initialContent = ""

  private var editor: EditorViewController?
  private var isReady = false
  private var latestTitle = ""
  private var latestContent = ""
  private var hasCreatedEditor = false
  private var snapshotTask: Task<Void, Never>?
  private var snapshotGeneration = 0
  private var isForwardingAppearance = false

  required init(appContext: AppContext? = nil) {
    super.init(appContext: appContext)
  }

  func loadEditorIfNeeded() {
    guard editor == nil else {
      return
    }

    let snapshot = hasCreatedEditor
      ? (title: latestTitle, content: latestContent)
      : (title: initialTitle, content: initialContent)
    let siteURL = URL(string: "https://example.invalid")!
    let configuration = EditorConfigurationBuilder(
      title: snapshot.title,
      content: snapshot.content,
      postType: .post,
      siteURL: siteURL,
      siteApiRoot: siteURL.appending(path: "wp-json")
    )
      .setIsOfflineModeEnabled(true)
      .setShouldUsePlugins(false)
      .setShouldUseThemeStyles(false)
      .build()

    let editor = EditorViewController(configuration: configuration)
    editor.delegate = self
    self.editor = editor
    latestTitle = snapshot.title
    latestContent = snapshot.content
    hasCreatedEditor = true

    attachEditorIfPossible()
  }

  override func didMoveToWindow() {
    super.didMoveToWindow()

    if window == nil {
      detachEditor()
      discardEditorIfLoading()
    } else {
      loadEditorIfNeeded()
      attachEditorIfPossible()
    }
  }

  private func attachEditorIfPossible() {
    guard
      let editor,
      editor.parent == nil,
      let containerController = reactViewController()
    else {
      return
    }

    let parentController = (containerController as? UINavigationController)?.visibleViewController
      ?? containerController
    let shouldForwardAppearance = parentController.viewIfLoaded?.window != nil

    parentController.addChild(editor)
    if shouldForwardAppearance {
      editor.beginAppearanceTransition(true, animated: false)
    }

    let editorView = editor.view!
    editorView.translatesAutoresizingMaskIntoConstraints = false
    addSubview(editorView)
    NSLayoutConstraint.activate([
      editorView.leadingAnchor.constraint(equalTo: leadingAnchor),
      editorView.topAnchor.constraint(equalTo: topAnchor),
      editorView.trailingAnchor.constraint(equalTo: trailingAnchor),
      editorView.bottomAnchor.constraint(equalTo: bottomAnchor)
    ])
    editor.didMove(toParent: parentController)

    if shouldForwardAppearance {
      editor.endAppearanceTransition()
    }
    isForwardingAppearance = shouldForwardAppearance
  }

  private func detachEditor() {
    guard let editor, editor.parent != nil else {
      return
    }

    if isReady {
      scheduleSnapshot(from: editor, delay: false)
    }

    if isForwardingAppearance {
      editor.beginAppearanceTransition(false, animated: false)
    }
    editor.willMove(toParent: nil)
    editor.view.removeFromSuperview()
    editor.removeFromParent()
    if isForwardingAppearance {
      editor.endAppearanceTransition()
    }
    isForwardingAppearance = false
  }

  private func discardEditorIfLoading() {
    guard !isReady, let editor else {
      return
    }

    snapshotGeneration += 1
    snapshotTask?.cancel()
    snapshotTask = nil
    editor.delegate = nil
    self.editor = nil
  }

  func requestContent() async throws -> [String: String] {
    guard isReady, let editor else {
      throw WPNativeEditorNotReadyError()
    }

    snapshotGeneration += 1
    snapshotTask?.cancel()
    snapshotTask = nil
    let result = try await editor.getTitleAndContent()
    latestTitle = result.title
    latestContent = result.content
    return ["title": result.title, "content": result.content]
  }

  func editorDidLoad(_ viewController: EditorViewController) {
    guard viewController === editor else {
      return
    }
    isReady = true
    onReady([:])
  }

  func editor(_ viewController: EditorViewController, didFailToLoad error: Error) {
    guard viewController === editor else {
      return
    }
    isReady = false
    onError(["message": error.localizedDescription])
  }

  func editor(_ viewController: EditorViewController, didEncounterCriticalError error: Error) {
    onError(["message": error.localizedDescription])
  }

  func editor(_ viewController: EditorViewController, didDisplayInitialContent content: String) {}

  func editor(_ viewController: EditorViewController, didUpdateContentWithState state: EditorState) {
    scheduleSnapshot(from: viewController, delay: true)
  }

  private func scheduleSnapshot(from viewController: EditorViewController, delay: Bool) {
    if delay, snapshotTask != nil {
      return
    }

    snapshotGeneration += 1
    let generation = snapshotGeneration
    snapshotTask?.cancel()
    snapshotTask = Task { [weak self, weak viewController] in
      if delay {
        do {
          try await Task.sleep(for: .milliseconds(250))
        } catch {
          return
        }
      }

      guard
        !Task.isCancelled,
        let self,
        let viewController,
        generation == snapshotGeneration,
        viewController === editor
      else {
        return
      }

      if let result = try? await viewController.getTitleAndContent() {
        latestTitle = result.title
        latestContent = result.content
      }
      if generation == snapshotGeneration {
        snapshotTask = nil
      }
    }
  }

  func editor(_ viewController: EditorViewController, didUpdateHistoryState state: EditorState) {}
  func editor(_ viewController: EditorViewController, didUpdateFeaturedImage mediaID: Int) {}
  func editor(_ viewController: EditorViewController, didLogException error: GutenbergJSException) {}
  func editor(_ viewController: EditorViewController, didRequestMediaFromSiteMediaLibrary config: OpenMediaLibraryAction) {}
  func editor(_ viewController: EditorViewController, didTriggerAutocompleter type: String) {}
  func editor(_ viewController: EditorViewController, didOpenModalDialog dialogType: String) {}
  func editor(_ viewController: EditorViewController, didCloseModalDialog dialogType: String) {}
  func editor(_ viewController: EditorViewController, didLogNetworkRequest request: RecordedNetworkRequest) {}

  func editorDidRequestLatestContent(_ controller: EditorViewController) -> (title: String, content: String)? {
    (latestTitle, latestContent)
  }
}
