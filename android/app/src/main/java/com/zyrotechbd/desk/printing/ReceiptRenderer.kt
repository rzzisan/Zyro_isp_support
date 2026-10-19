package com.zyrotechbd.desk.printing

import android.annotation.SuppressLint
import android.app.Activity
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.view.View
import android.view.ViewGroup
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import com.zyrotechbd.desk.BuildConfig
import kotlinx.coroutines.delay
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeout
import kotlin.coroutines.resume
import kotlin.math.ceil

/**
 * Draws the Desk's own receipt page (the same HTML the panel prints) into a black-and-white-ready bitmap exactly
 * as wide as the printer's dots, so the phone prints what the office prints: QR, Bangla text, company details.
 */
object ReceiptRenderer {
    private var slowDrawEnabled = false

    /** The receipt's content box (.r in print/receipt.blade.php) is this wide; it is scaled to the printer's dots. */
    private fun contentMm(paperMm: Int) = if (paperMm == 80) 72.0 else 50.0

    @SuppressLint("SetJavaScriptEnabled")
    suspend fun render(activity: Activity, html: String, paperMm: Int, dots: Int): Bitmap {
        if (!slowDrawEnabled) {
            WebView.enableSlowWholeDocumentDraw()  // draw the whole page, not just what is on screen
            slowDrawEnabled = true
        }
        // CSS px per mm is 96 / 25.4; scale so the content box fills the paper's printable dots
        val scalePercent = dots / (contentMm(paperMm) * 96 / 25.4) * 100
        // The WebView is a "screen", so the receipt's @media screen preview style (side padding, gray page, shadow)
        // applies; strip it, or the content shrinks to the middle of the paper with blank bands on both sides.
        val page = html.replace("</head>", "<style>html,body{width:auto!important;margin:0!important;" +
            "background:#fff!important}.r{margin:0!important;padding:0!important;box-shadow:none!important;" +
            "background:#fff!important}</style></head>")

        val root = activity.findViewById<ViewGroup>(android.R.id.content)
        val webView = WebView(activity).apply {
            setLayerType(View.LAYER_TYPE_SOFTWARE, null)
            setBackgroundColor(Color.WHITE)
            settings.javaScriptEnabled = true
            settings.useWideViewPort = false
            settings.loadWithOverviewMode = false
            setInitialScale(scalePercent.toInt())
            isVerticalScrollBarEnabled = false
            alpha = 0f  // laid out (so it renders) but never visible
        }
        root.addView(webView, FrameLayout.LayoutParams(dots, 3000))
        try {
            return withTimeout(30_000) {
                suspendCancellableCoroutine<Unit> { cont ->
                    webView.webViewClient = object : WebViewClient() {
                        override fun onPageFinished(view: WebView, url: String?) {
                            if (cont.isActive) cont.resume(Unit)
                        }
                    }
                    webView.loadDataWithBaseURL(BuildConfig.DESK_URL, page, "text/html", "UTF-8", null)
                }
                // web fonts (Noto Sans Bengali) and the QR code settle after the page itself
                var bottomCss = 0.0
                for (attempt in 0 until 20) {
                    val r = webView.evaluate("(document.fonts ? document.fonts.status : 'loaded') + '|' + " +
                        "Math.ceil((document.querySelector('.r') || document.body).getBoundingClientRect().bottom)")
                    val parts = r.trim('"').split('|')
                    bottomCss = parts.getOrNull(1)?.toDoubleOrNull() ?: 0.0
                    if (parts.firstOrNull() == "loaded" && bottomCss > 0) break
                    delay(250)
                }
                delay(200)
                val heightPx = ceil((bottomCss + 2) * scalePercent / 100).toInt().coerceIn(50, 12_000)
                webView.layoutParams = FrameLayout.LayoutParams(dots, heightPx)
                webView.measure(View.MeasureSpec.makeMeasureSpec(dots, View.MeasureSpec.EXACTLY),
                    View.MeasureSpec.makeMeasureSpec(heightPx, View.MeasureSpec.EXACTLY))
                webView.layout(0, 0, dots, heightPx)
                delay(300)
                val bitmap = Bitmap.createBitmap(dots, heightPx, Bitmap.Config.ARGB_8888)
                val canvas = Canvas(bitmap)
                canvas.drawColor(Color.WHITE)
                webView.draw(canvas)
                bitmap
            }
        } finally {
            root.removeView(webView)
            webView.destroy()
        }
    }

    private suspend fun WebView.evaluate(js: String): String = suspendCancellableCoroutine { cont ->
        evaluateJavascript(js) { if (cont.isActive) cont.resume(it ?: "") }
    }
}
