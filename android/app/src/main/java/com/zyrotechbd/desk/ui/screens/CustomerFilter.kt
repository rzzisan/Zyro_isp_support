package com.zyrotechbd.desk.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.Saver
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.zyrotechbd.desk.data.network.Choice
import com.zyrotechbd.desk.data.network.CustomerFilterChoices
import com.zyrotechbd.desk.ui.components.Chip
import com.zyrotechbd.desk.ui.components.Field
import com.zyrotechbd.desk.ui.components.Picker
import com.zyrotechbd.desk.ui.components.PrimaryButton
import com.zyrotechbd.desk.ui.components.SecondaryButton
import com.zyrotechbd.desk.ui.strings.AppStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Surface
import java.time.LocalDate

/**
 * The customer list's filters, like the panel's: place (zone → subzone → box), package, status, expiry day (billing's
 * Ex.Date, the bill day of the month), due, line, online now, and current or Left customers. Null means "all".
 */
data class CustomerFilter(
    val zone: String? = null,
    val subzone: String? = null,
    val box: String? = null,
    val pkg: String? = null,
    val status: String? = null,
    val exp: String? = null,     // today, tomorrow, next3, next7, past7 or d<day>
    val due: Boolean? = null,
    val lineOn: Boolean? = null,
    val online: Boolean? = null,
    val left: Boolean = false,
) {
    val count get() = listOfNotNull(zone, subzone, box, pkg, status, exp, due, lineOn, online).size + (if (left) 1 else 0)

    /** The expiry filter as bill days of the month (from > to wraps past the month's end). */
    fun expRange(today: LocalDate = LocalDate.now()): Pair<Int, Int>? {
        fun day(n: Long) = today.plusDays(n).dayOfMonth
        return when (exp) {
            null -> null
            "today" -> day(0) to day(0)
            "tomorrow" -> day(1) to day(1)
            "next3" -> day(0) to day(2)
            "next7" -> day(0) to day(6)
            "past7" -> day(-7) to day(-1)
            else -> exp.removePrefix("d").toIntOrNull()?.let { it to it }
        }
    }

    companion object {
        val Saver = Saver<CustomerFilter, List<String?>>(
            save = { listOf(it.zone, it.subzone, it.box, it.pkg, it.status, it.exp, it.due?.toString(), it.lineOn?.toString(),
                it.online?.toString(), it.left.toString()) },
            restore = { CustomerFilter(it[0], it[1], it[2], it[3], it[4], it[5], it[6]?.toBoolean(), it[7]?.toBoolean(),
                it[8]?.toBoolean(), it[9].toBoolean()) },
        )
    }
}

fun expLabel(exp: String, s: AppStrings): String = when (exp) {
    "today" -> s.fToday
    "tomorrow" -> s.fTomorrow
    "next3" -> s.fNext3
    "next7" -> s.fNext7
    "past7" -> s.fPast7
    else -> "${s.fDay} ${exp.removePrefix("d")}"
}

/** One chip per filter in use, with what clears it. */
fun activeFilters(f: CustomerFilter, s: AppStrings): List<Pair<String, CustomerFilter>> = listOfNotNull(
    f.zone?.let { "${s.fZone}: $it" to f.copy(zone = null, subzone = null, box = null) },
    f.subzone?.let { "${s.fSubzone}: $it" to f.copy(subzone = null, box = null) },
    f.box?.let { "${s.fBox}: $it" to f.copy(box = null) },
    f.pkg?.let { "${s.fPackage}: $it" to f.copy(pkg = null) },
    f.status?.let { "${s.fStatus}: $it" to f.copy(status = null) },
    f.exp?.let { "${s.fExpireShort}: ${expLabel(it, s)}" to f.copy(exp = null) },
    f.due?.let { (if (it) s.fHasDue else s.fNoDue) to f.copy(due = null) },
    f.lineOn?.let { (if (it) s.fLineOn else s.fLineOff) to f.copy(lineOn = null) },
    f.online?.let { (if (it) s.fOnline else s.fOffline) to f.copy(online = null) },
    if (f.left) s.fLeft to f.copy(left = false) else null,
)

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun CustomerFilterSheet(start: CustomerFilter, choices: CustomerFilterChoices, s: AppStrings, onDismiss: () -> Unit,
                        onApply: (CustomerFilter) -> Unit) {
    val sheet = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheet, containerColor = Surface) {
        CustomerFilterForm(start, choices, s, onApply)
    }
}

/** The filter sheet's form; the choices apply only on "show". */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun CustomerFilterForm(start: CustomerFilter, choices: CustomerFilterChoices, s: AppStrings, onApply: (CustomerFilter) -> Unit) {
    var f by remember { mutableStateOf(start) }

    // the chosen value stays a choice even when the lists have not loaded it
    fun pick(values: List<String>, chosen: String? = null) =
        listOf(Choice("", s.fAll)) + (values + listOfNotNull(chosen)).filter { it.isNotBlank() }.distinct().sorted().map { Choice(it, it) }
    val places = choices.places
    val zones = pick(places.map { it.zone }, f.zone)
    val subzones = pick(places.filter { f.zone == null || it.zone == f.zone }.map { it.subzone }, f.subzone)
    val boxes = pick(places.filter { (f.zone == null || it.zone == f.zone) && (f.subzone == null || it.subzone == f.subzone) }.map { it.box }, f.box)
    val exps = listOf(Choice("", s.fAll)) + listOf("today", "tomorrow", "next3", "next7", "past7").map { Choice(it, expLabel(it, s)) } +
        (1..31).map { Choice("d$it", "${s.fDay} $it") }

    @Composable
    fun <T> Options(label: String, value: T, options: List<Pair<T, String>>, onPick: (T) -> Unit) {
        Field(label) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                options.forEach { (v, name) -> Chip(name, value == v) { onPick(v) } }
            }
        }
        Spacer(Modifier.height(12.dp))
    }

    run {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).navigationBarsPadding()) {
            Text(s.fFilter, fontSize = 17.sp, fontWeight = FontWeight.SemiBold, color = Ink)
            Spacer(Modifier.height(12.dp))
            Field(s.fZone) {
                Picker(zones, f.zone ?: "") { z -> f = f.copy(zone = z.ifEmpty { null }, subzone = null, box = null) }
            }
            Spacer(Modifier.height(10.dp))
            Row {
                Field(s.fSubzone, Modifier.weight(1f)) {
                    Picker(subzones, f.subzone ?: "") { z -> f = f.copy(subzone = z.ifEmpty { null }, box = null) }
                }
                Spacer(Modifier.width(10.dp))
                Field(s.fBox, Modifier.weight(1f)) { Picker(boxes, f.box ?: "") { b -> f = f.copy(box = b.ifEmpty { null }) } }
            }
            Spacer(Modifier.height(10.dp))
            Field(s.fExpire) { Picker(exps, f.exp ?: "") { e -> f = f.copy(exp = e.ifEmpty { null }) } }
            Spacer(Modifier.height(10.dp))
            Row {
                Field(s.fPackage, Modifier.weight(1f)) {
                    Picker(pick(choices.packages, f.pkg), f.pkg ?: "") { p -> f = f.copy(pkg = p.ifEmpty { null }) }
                }
                Spacer(Modifier.width(10.dp))
                Field(s.fStatus, Modifier.weight(1f)) {
                    Picker(pick(choices.statuses, f.status), f.status ?: "") { v -> f = f.copy(status = v.ifEmpty { null }) }
                }
            }
            Spacer(Modifier.height(12.dp))
            Options(s.fDue, f.due, listOf(null to s.fAll, true to s.fHasDue, false to s.fNoDue)) { f = f.copy(due = it) }
            Options(s.fLine, f.lineOn, listOf(null to s.fAll, true to s.fLineOn, false to s.fLineOff)) { f = f.copy(lineOn = it) }
            Options(s.fNow, f.online, listOf(null to s.fAll, true to s.fOnline, false to s.fOffline)) { f = f.copy(online = it) }
            Options(s.fList, f.left, listOf(false to s.fCurrent, true to s.fLeft)) { f = f.copy(left = it) }
            Row {
                SecondaryButton(s.fClear, modifier = Modifier.weight(1f)) { f = CustomerFilter() }
                Spacer(Modifier.width(10.dp))
                PrimaryButton(s.fApply, modifier = Modifier.weight(1f)) { onApply(f) }
            }
            Spacer(Modifier.height(16.dp))
        }
    }
}
