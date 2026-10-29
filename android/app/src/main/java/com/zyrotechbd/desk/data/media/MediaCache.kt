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

    suspend fun file(context: Context, company: String, messageId: Long, extension: String): File =
        fetch(File(dir(context), "$company-$messageId.$extension"), "c/$company/media/$messageId")

    /** A hotline call's recording (mp3) from /c/{company}/calls/{id}/recording. */
    suspend fun recording(context: Context, company: String, callId: Long): File =
        fetch(File(dir(context), "$company-call-$callId.mp3"), "c/$company/calls/$callId/recording")

    /** A team chat photo or voice message from /c/{company}/team-chat/media/{id}. */
    suspend fun teamFile(context: Context, company: String, messageId: Long, extension: String): File =
        fetch(File(dir(context), "$company-team-$messageId.$extension"), "c/$company/team-chat/media/$messageId")

    suspend fun teamImage(context: Context, company: String, messageId: Long, maxPx: Int = 1024): Bitmap? =
        decode(teamFile(context, company, messageId, "jpg"), maxPx)

    private suspend fun fetch(file: File, path: String): File = withContext(Dispatchers.IO) {
        if (file.length() > 0) return@withContext file
        val request = Request.Builder().url(ApiClient.baseUrl + path).build()
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
        return decode(file(context, company, messageId, "img"), maxPx)
    }

    private suspend fun decode(file: File, maxPx: Int): Bitmap? {
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
