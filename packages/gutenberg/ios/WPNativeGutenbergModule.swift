// Copyright (C) 2026 Chris Huber
// SPDX-License-Identifier: MIT

import ExpoModulesCore

public final class WPNativeGutenbergModule: Module {
  public func definition() -> ModuleDefinition {
    Name("WPNativeGutenberg")

    View(GutenbergEditorView.self) {
      Prop("initialTitle") { (view: GutenbergEditorView, title: String) in
        MainActor.assumeIsolated {
          view.initialTitle = title
        }
      }

      Prop("initialContent") { (view: GutenbergEditorView, content: String) in
        MainActor.assumeIsolated {
          view.initialContent = content
        }
      }

      Events("onReady", "onError")

      OnViewDidUpdateProps { (view: GutenbergEditorView) in
        MainActor.assumeIsolated {
          view.loadEditorIfNeeded()
        }
      }

      AsyncFunction("requestContent") { (view: GutenbergEditorView) async throws in
        try await view.requestContent()
      }
    }
  }
}
