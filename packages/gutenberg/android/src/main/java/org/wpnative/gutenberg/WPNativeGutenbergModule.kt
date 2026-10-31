// Copyright (C) 2026 Chris Huber
// SPDX-License-Identifier: MIT

package org.wpnative.gutenberg

import expo.modules.kotlin.Promise
import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition

class WPNativeGutenbergModule : Module() {
    override fun definition() = ModuleDefinition {
        Name("WPNativeGutenberg")

        View(GutenbergEditorView::class) {
            Prop("initialTitle") { view: GutenbergEditorView, title: String ->
                view.setInitialTitle(title)
            }

            Prop("initialContent") { view: GutenbergEditorView, content: String ->
                view.setInitialContent(content)
            }

            Events("onReady", "onError")

            OnViewDidUpdateProps { view: GutenbergEditorView ->
                view.loadEditorIfNeeded()
            }

            AsyncFunction("requestContent") { view: GutenbergEditorView, promise: Promise ->
                view.requestContent(promise)
            }
        }
    }
}
