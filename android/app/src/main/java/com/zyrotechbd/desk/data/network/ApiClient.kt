package com.zyrotechbd.desk.data.network

import com.squareup.moshi.Moshi
import com.squareup.moshi.kotlin.reflect.KotlinJsonAdapterFactory
import com.zyrotechbd.desk.BuildConfig
import com.zyrotechbd.desk.data.auth.SessionStore
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.HttpException
import retrofit2.Retrofit
import retrofit2.converter.moshi.MoshiConverterFactory
import java.io.IOException
import java.util.concurrent.TimeUnit

object ApiClient {
    val moshi: Moshi = Moshi.Builder()
        .addLast(KotlinJsonAdapterFactory())
        .build()

    /** Called when the server says the token is no longer valid (signed out elsewhere, user removed). */
    @Volatile
    var onUnauthorized: (() -> Unit)? = null

    val httpClient: OkHttpClient by lazy {
        val logger = HttpLoggingInterceptor().apply {
            // headers only: bodies can hold customers' details
            level = if (BuildConfig.DEBUG) HttpLoggingInterceptor.Level.BASIC else HttpLoggingInterceptor.Level.NONE
        }
        val authInterceptor = okhttp3.Interceptor { chain ->
            val builder = chain.request().newBuilder().header("Accept", "application/json")
            SessionStore.token()?.let { builder.header("Authorization", "Bearer $it") }
            val response = chain.proceed(builder.build())
            if (response.code == 401 && SessionStore.token() != null) onUnauthorized?.invoke()
            response
        }
        OkHttpClient.Builder()
            .addInterceptor(authInterceptor)
            .addInterceptor(logger)
            .connectTimeout(20, TimeUnit.SECONDS)
            // a bill goes to the billing software through the engine; that can take over a minute
            .readTimeout(100, TimeUnit.SECONDS)
            .build()
    }

    val service: ApiService by lazy {
        Retrofit.Builder()
            .baseUrl(BuildConfig.API_BASE_URL)
            .client(httpClient)
            .addConverterFactory(MoshiConverterFactory.create(moshi))
            .build()
            .create(ApiService::class.java)
    }

    /** The server's own message (Laravel {"message": ...} or the first validation error), else a plain fallback. */
    fun errorMessage(e: Throwable, fallback: String, offline: String): String = when (e) {
        is HttpException -> {
            val body = runCatching { e.response()?.errorBody()?.string() }.getOrNull()
            val parsed = body?.let { runCatching { moshi.adapter(ApiError::class.java).fromJson(it) }.getOrNull() }
            parsed?.errors?.values?.firstOrNull()?.firstOrNull() ?: parsed?.message?.takeIf { it.isNotBlank() } ?: fallback
        }
        is IOException -> offline
        else -> fallback
    }
}
