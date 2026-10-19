package com.zyrotechbd.desk.data.media

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import com.zyrotechbd.desk.data.network.ApiClient
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.Request
import java.io.File
import java.io.IOException

/**
 * Inbox media (voice, photos, files) fetched with the app's token from /c/{company}/media/{id} and kept in the
 * app's cache, so a chat scrolled back and forth downloads each file once.
 */
object MediaCache {
    private fun dir(context: Context) = File(context.cacheDir, "media").apply { mkdirs() }

    suspend fun file(context: Context, company: String, messageId: Long, extension: String): File = withContext(Dispatchers.IO) {
        val file = File(dir(context), "$company-$messageId.$extension")
        if (file.length() > 0) return@withContext file
        val request = Request.Builder().url(ApiClient.baseUrl + "c/$company/media/$messageId").build()
        ApiClient.httpClient.newCall(request).execute().use { res ->
            if (!res.isSuccessful) throw IOException("media ${res.code}")
            val tmp = File(file.path + ".part")
            tmp.outputStream().use { out -> res.body!!.byteStream().copyTo(out) }
            tmp.renameTo(file)
        }
        file
    }

    /** A photo scaled down to about [maxPx] on its long side, for showing in the chat. */
    suspend fun image(context: Context, company: String, messageId: Long, maxPx: Int = 1024): Bitmap? {
        val file = file(context, company, messageId, "img")
        return withContext(Dispatchers.IO) {
            val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            BitmapFactory.decodeFile(file.path, bounds)
            var sample = 1
            while (maxOf(bounds.outWidth, bounds.outHeight) / (sample * 2) >= maxPx) sample *= 2
            BitmapFactory.decodeFile(file.path, BitmapFactory.Options().apply { inSampleSize = sample })
        }
    }

    fun extensionFor(mime: String?): String = when {
        mime == null -> "bin"
        mime.startsWith("image/") -> "img"
        mime == "application/pdf" -> "pdf"
        mime.startsWith("video/") -> "mp4"
        mime.contains("ogg") -> "ogg"
        mime.contains("mpeg") -> "mp3"
        mime.startsWith("audio/") -> "m4a"
        else -> "bin"
    }
}
