package com.zyrotechbd.desk.core

import java.text.DecimalFormat
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

object Format {
    val dhaka: ZoneId = ZoneId.of("Asia/Dhaka")
    private val money = DecimalFormat("#,##0.##")
    private val dateTime = DateTimeFormatter.ofPattern("d MMM, h:mm a", Locale.ENGLISH)
    private val date = DateTimeFormatter.ofPattern("d MMM yyyy", Locale.ENGLISH)
    private val time = DateTimeFormatter.ofPattern("h:mm a", Locale.ENGLISH)
    private val dayMonth = DateTimeFormatter.ofPattern("dd MMM", Locale.ENGLISH)

    fun tk(v: Double?): String = "৳" + money.format(v ?: 0.0)

    fun amount(v: Double?): String = money.format(v ?: 0.0)

    /** "12 Oct, 3:45 PM" in Bangladesh time, from an ISO time from the API. */
    fun dateTime(iso: String?): String = parse(iso)?.format(dateTime) ?: ""

    fun time(iso: String?): String = parse(iso)?.format(time) ?: ""

    /** For a chat list: "10:24 AM" today, [yesterday] for yesterday, else "06 Oct" (like the panel's inbox). */
    fun shortTime(iso: String?, yesterday: String): String {
        val at = parse(iso) ?: return ""
        val day = at.toLocalDate()
        return when (day) {
            today() -> at.format(time)
            today().minusDays(1) -> yesterday
            else -> at.format(dayMonth)
        }
    }

    /** "12 Oct 2026" from "2026-10-12". */
    fun date(ymd: String?): String = runCatching { LocalDate.parse(ymd).format(date) }.getOrDefault(ymd ?: "")

    fun date(d: LocalDate): String = d.format(date)

    fun today(): LocalDate = LocalDate.now(dhaka)

    private fun parse(iso: String?) = iso?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(dhaka) }.getOrNull() }
}
