package com.zyrotechbd.desk.data.auth

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.squareup.moshi.Moshi
import com.squareup.moshi.Types
import com.squareup.moshi.kotlin.reflect.KotlinJsonAdapterFactory
import com.zyrotechbd.desk.core.AppContext
import com.zyrotechbd.desk.data.network.CompanyInfo

/**
 * The signed-in session: the Desk API token (encrypted with the Android Keystore), the user's name and the
 * companies they work in, plus which company the app is showing.
 */
object SessionStore {
    private const val KEY_TOKEN = "token"
    private const val KEY_NAME = "user_name"
    private const val KEY_EMAIL = "user_email"
    private const val KEY_COMPANIES = "companies"
    private const val KEY_COMPANY = "company_slug"

    private val companiesAdapter by lazy {
        Moshi.Builder().addLast(KotlinJsonAdapterFactory()).build()
            .adapter<List<CompanyInfo>>(Types.newParameterizedType(List::class.java, CompanyInfo::class.java))
    }

    private val prefs: SharedPreferences by lazy {
        val ctx = AppContext.app
        try {
            val key = MasterKey.Builder(ctx).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build()
            EncryptedSharedPreferences.create(
                ctx, "desk_session", key,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
            )
        } catch (e: Exception) {
            // keystore broken on this phone (rare, some old ROMs): start over signed out rather than crash
            ctx.deleteSharedPreferences("desk_session")
            ctx.getSharedPreferences("desk_session_plain", Context.MODE_PRIVATE)
        }
    }

    fun token(): String? = prefs.getString(KEY_TOKEN, null)
    fun userName(): String = prefs.getString(KEY_NAME, null) ?: ""
    fun userEmail(): String = prefs.getString(KEY_EMAIL, null) ?: ""

    fun companies(): List<CompanyInfo> =
        prefs.getString(KEY_COMPANIES, null)?.let { runCatching { companiesAdapter.fromJson(it) }.getOrNull() } ?: emptyList()

    /** The company the app shows; the first one when none was picked (or it is gone). */
    fun company(): CompanyInfo? {
        val all = companies()
        val slug = prefs.getString(KEY_COMPANY, null)
        return all.firstOrNull { it.slug == slug } ?: all.firstOrNull()
    }

    fun save(token: String?, name: String, email: String, companies: List<CompanyInfo>) {
        prefs.edit().apply {
            if (token != null) putString(KEY_TOKEN, token)
            putString(KEY_NAME, name)
            putString(KEY_EMAIL, email)
            putString(KEY_COMPANIES, companiesAdapter.toJson(companies))
        }.apply()
    }

    fun selectCompany(slug: String) {
        prefs.edit().putString(KEY_COMPANY, slug).apply()
    }

    fun clear() {
        prefs.edit().clear().apply()
    }
}
