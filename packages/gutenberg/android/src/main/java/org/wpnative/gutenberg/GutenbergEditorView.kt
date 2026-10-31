// Copyright (C) 2026 Chris Huber
// SPDX-License-Identifier: MIT

package org.wpnative.gutenberg

import android.content.Context
import expo.modules.kotlin.AppContext
import expo.modules.kotlin.Promise
import expo.modules.kotlin.viewevent.EventDispatcher
import expo.modules.kotlin.views.ExpoView
import kotlinx.coroutines.MainScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.wordpress.gutenberg.GutenbergView
import org.wordpress.gutenberg.model.EditorConfiguration
import org.wordpress.gutenberg.model.PostTypeDetails
import java.util.concurrent.atomic.AtomicReference

class GutenbergEditorView(context: Context, appContext: AppContext) : ExpoView(context, appContext) {
    val onReady by EventDispatcher()
    val onError by EventDispatcher()

    private var initialTitle = ""
    private var initialContent = ""
    private var hasCreatedEditor = false
    private var scope = MainScope()
    private var editor: GutenbergView? = null
    private var isReady = false
    private var pendingContentPromise: Promise? = null
    private var snapshotJob: Job? = null
    private val latestSnapshot = AtomicReference(EditorSnapshot("", ""))

    fun setInitialTitle(title: String) {
        initialTitle = title
        if (!hasCreatedEditor) {
            latestSnapshot.updateAndGet { it.copy(title = title) }
        }
    }

    fun setInitialContent(content: String) {
        initialContent = content
        if (!hasCreatedEditor) {
            latestSnapshot.updateAndGet { it.copy(content = content) }
        }
    }

    fun loadEditorIfNeeded() {
        if (editor != null) {
            return
        }

        val snapshot = if (hasCreatedEditor) {
            latestSnapshot.get()
        } else {
            EditorSnapshot(initialTitle, initialContent).also(latestSnapshot::set)
        }

        val configuration = EditorConfiguration.builder(
            "https://example.invalid",
            "https://example.invalid/wp-json",
            PostTypeDetails.post
        )
            .setTitle(snapshot.title)
            .setContent(snapshot.content)
            .setPlugins(false)
            .setThemeStyles(false)
            .setEnableOfflineMode(true)
            .build()

        try {
            val editor = GutenbergView(configuration, null, scope, context).apply {
                layoutParams = LayoutParams(LayoutParams.MATCH_PARENT, LayoutParams.MATCH_PARENT)
                setEditorDidBecomeAvailable { availableEditor ->
                    if (this@GutenbergEditorView.editor !== availableEditor) {
                        return@setEditorDidBecomeAvailable
                    }
                    isReady = true
                    onReady(emptyMap())
                }
                setLatestContentProvider(object : GutenbergView.LatestContentProvider {
                    override fun getLatestContent(): GutenbergView.LatestContent {
                        val latest = latestSnapshot.get()
                        return GutenbergView.LatestContent(latest.title, latest.content)
                    }
                })
                setContentChangeListener(object : GutenbergView.ContentChangeListener {
                    override fun onContentChanged() {
                        scheduleSnapshot()
                    }
                })
            }
            hasCreatedEditor = true
            this.editor = editor
            addView(editor)
        } catch (error: Exception) {
            onError(mapOf("message" to (error.localizedMessage ?: "The editor failed to load.")))
        }
    }

    fun requestContent(promise: Promise) {
        val editor = editor
        if (!isReady || editor == null) {
            promise.reject("ERR_EDITOR_NOT_READY", "The Gutenberg editor is not ready.", null)
            return
        }

        pendingContentPromise?.reject(
            "ERR_REQUEST_SUPERSEDED",
            "A newer Gutenberg content request superseded this request.",
            null
        )
        pendingContentPromise = promise

        val originalContent = latestSnapshot.get().content
        editor.getTitleAndContent(
            originalContent,
            object : GutenbergView.TitleAndContentCallback {
                override fun onResult(title: CharSequence, content: CharSequence) {
                    if (pendingContentPromise !== promise) {
                        return
                    }
                    val snapshot = EditorSnapshot(title.toString(), content.toString())
                    latestSnapshot.set(snapshot)
                    pendingContentPromise = null
                    promise.resolve(mapOf("title" to snapshot.title, "content" to snapshot.content))
                }
            },
            true
        )
    }

    private fun scheduleSnapshot() {
        if (snapshotJob?.isActive == true) {
            return
        }

        snapshotJob = scope.launch {
            delay(SNAPSHOT_INTERVAL_MS)
            captureContent()
            snapshotJob = null
        }
    }

    private fun captureContent() {
        val editor = editor ?: return
        val originalContent = latestSnapshot.get().content
        editor.getTitleAndContent(
            originalContent,
            object : GutenbergView.TitleAndContentCallback {
                override fun onResult(title: CharSequence, content: CharSequence) {
                    if (this@GutenbergEditorView.editor !== editor) {
                        return
                    }
                    latestSnapshot.set(EditorSnapshot(title.toString(), content.toString()))
                }
            },
            true
        )
    }

    override fun onAttachedToWindow() {
        super.onAttachedToWindow()
        loadEditorIfNeeded()
    }

    override fun onDetachedFromWindow() {
        pendingContentPromise?.reject(
            "ERR_EDITOR_DETACHED",
            "The Gutenberg editor detached before the content request completed.",
            null
        )
        pendingContentPromise = null
        snapshotJob?.cancel()
        snapshotJob = null
        removeAllViews()
        editor = null
        isReady = false
        scope.cancel()
        scope = MainScope()
        super.onDetachedFromWindow()
    }

    private data class EditorSnapshot(val title: String, val content: String)

    private companion object {
        const val SNAPSHOT_INTERVAL_MS = 250L
    }
}
