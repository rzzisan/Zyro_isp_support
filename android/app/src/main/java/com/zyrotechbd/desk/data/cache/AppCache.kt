package com.zyrotechbd.desk.data.cache

import java.util.concurrent.ConcurrentHashMap

object AppCache {
    private data class Entry<T>(val data: T, val time: Long)

    private val store = ConcurrentHashMap<String, Entry<*>>()

    @Suppress("UNCHECKED_CAST")
    fun <T> get(key: String, ttlMs: Long): T? {
        val e = store[key] as? Entry<T> ?: return null
        return if (System.currentTimeMillis() - e.time <= ttlMs) e.data
        else null.also { store.remove(key) }
    }

    fun <T> put(key: String, data: T) {
        store[key] = Entry(data, System.currentTimeMillis())
    }

    fun invalidate(vararg keys: String) = keys.forEach { store.remove(it) }

    fun clear() = store.clear()

    // Returns age in seconds, -1 if not cached
    fun ageSeconds(key: String): Long {
        val e = store[key] ?: return -1L
        return (System.currentTimeMillis() - e.time) / 1000L
    }

    // TTL constants (milliseconds)
    const val TTL_DASHBOARD = 5 * 60 * 1000L   // 5 minutes
    const val TTL_CUSTOMERS = 5 * 60 * 1000L   // 5 minutes
    const val TTL_BILLING   = 2 * 60 * 1000L   // 2 minutes — shorter for billing accuracy

    // Cache keys
    const val DASHBOARD_STATS      = "dashboard_stats"
    const val DASHBOARD_COLLECTION = "dashboard_collection"
    const val DASHBOARD_MONTHLY    = "dashboard_monthly"
    const val DASHBOARD_DEPOSIT    = "dashboard_deposit"
    const val CUSTOMERS            = "customers"
    const val BILLING_CURRENT      = "billing_current"
}
