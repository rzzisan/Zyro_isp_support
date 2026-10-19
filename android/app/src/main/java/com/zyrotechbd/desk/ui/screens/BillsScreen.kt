package com.zyrotechbd.desk.ui.screens

import android.app.Activity
import android.app.DatePickerDialog
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.horizontalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material.icons.filled.Print
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.DailyBill
import com.zyrotechbd.desk.data.network.DeskCollection
import com.zyrotechbd.desk.data.network.Totals
import com.zyrotechbd.desk.printing.PrinterManager
import com.zyrotechbd.desk.printing.ReceiptRef
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.DeskTextField
import com.zyrotechbd.desk.ui.components.Chip
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.EmptyText
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.components.LoadingBox
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.Pill
import com.zyrotechbd.desk.ui.components.SmallAction
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.strings.AppStrings
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.PrimaryStrong
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.LocalDate

/** বিল কালেকশন: billing's daily collection list (what this member may see), and the bills they took today. */
@Composable
fun BillsScreen(company: String) {
    val s = LocalStrings.current
    var tab by remember { mutableStateOf(0) }
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Chip(s.billingList, tab == 0) { tab = 0 }
            Chip(s.myCollectionToday, tab == 1) { tab = 1 }
        }
        if (tab == 0) DailyBillsTab(company) else MyCollectionsTab(company)
    }
}

/** Prints one receipt and shows how it went under the row that asked. */
private class PrintState {
    var busyKey by mutableStateOf<String?>(null)
    var message by mutableStateOf<Pair<String, String>?>(null)  // row key to message
}

@Composable
private fun DailyBillsTab(company: String) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val activity = context as Activity
    val scope = rememberCoroutineScope()
    var date by remember { mutableStateOf(Format.today()) }
    var status by remember { mutableStateOf("active") }
    var query by remember { mutableStateOf("") }
    var totals by remember { mutableStateOf<Totals?>(null) }
    val rows = remember { mutableStateListOf<DailyBill>() }
    var nextPage by remember { mutableStateOf<Int?>(null) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val printer = remember { PrintState() }
    val listState = rememberLazyListState()

    suspend fun fetch(page: Int) {
        loading = true
        error = null
        try {
            val d = date.toString()
            val res = ApiClient.service.dailyBills(company, d, d, status, query.trim().ifEmpty { null }, page)
            if (page == 1) rows.clear()
            rows.addAll(res.data)
            totals = res.totals
            nextPage = res.nextPage
        } catch (e: Exception) {
            error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
        } finally {
            loading = false
        }
    }

    LaunchedEffect(date, status, query) {
        if (query.isNotEmpty()) delay(350)
        fetch(1)
    }
    val nearEnd by remember { derivedStateOf { listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0 } }
    LaunchedEffect(nearEnd) {
        val next = nextPage
        if (next != null && !loading && nearEnd >= rows.size - 5) fetch(next)
    }

    fun print(b: DailyBill) {
        val key = "b${b.dailyBillId}"
        scope.launch {
            printer.busyKey = key
            printer.message = key to s.printingMsg
            printer.message = key to (PrinterManager.printReceipt(activity, ReceiptRef.DailyBill(b.dailyBillId), s) ?: s.printSuccessMsg)
            printer.busyKey = null
        }
    }

    LazyColumn(state = listState, contentPadding = PaddingValues(start = 16.dp, end = 16.dp, bottom = 24.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp)) {
        item {
            Row(verticalAlignment = Alignment.CenterVertically) {
                SmallAction(Format.date(date), Icons.Filled.CalendarMonth) {
                    DatePickerDialog(context, { _, y, m, d -> date = LocalDate.of(y, m + 1, d) },
                        date.year, date.monthValue - 1, date.dayOfMonth).show()
                }
                Spacer(Modifier.width(8.dp))
                DeskTextField(query, { query = it }, s.searchCustomer, modifier = Modifier.weight(1f))
            }
            Spacer(Modifier.height(8.dp))
            Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf("active" to s.statusAll, "pending" to s.statusPending, "approved" to s.statusApproved,
                    "deleted" to s.statusDeleted).forEach { (k, label) -> Chip(label, status == k) { status = k } }
            }
        }
        totals?.let { t ->
            item {
                DeskCard {
                    Row(Modifier.fillMaxWidth().padding(12.dp)) {
                        TotalCell(s.billCount(t.count), Format.tk(t.paid), Good, Modifier.weight(1f))
                        TotalCell(s.discountLabel, Format.tk(t.discount), Ink, Modifier.weight(1f))
                        TotalCell(s.due, Format.tk(t.due), Danger, Modifier.weight(1f))
                    }
                }
            }
        }
        error?.let { item { MessageBox(it, onRetry = { scope.launch { fetch(1) } }, retryLabel = s.retry) } }
        if (rows.isEmpty() && !loading && error == null) item { EmptyText(s.noBills) }
        items(rows, key = { it.dailyBillId }) { b ->
            val key = "b${b.dailyBillId}"
            BillRow(
                title = b.customerName ?: "", sub = listOfNotNull(b.customerId?.let { "ID $it" }, b.username).joinToString(" · "),
                meta = listOfNotNull(Format.time(b.collectedAt), b.method, b.receivedBy).filter { it.isNotBlank() }.joinToString(" · "),
                paid = b.paid, discount = b.discount, due = b.due, receiptNo = b.receiptNo ?: "B-${b.dailyBillId}",
                status = b.status, s = s, printing = printer.busyKey == key,
                message = printer.message?.takeIf { it.first == key }?.second,
                canPrint = b.status != "deleted", onPrint = { print(b) },
            )
        }
        if (loading) item { LoadingBox() }
    }
}

@Composable
private fun MyCollectionsTab(company: String) {
    val s = LocalStrings.current
    val activity = LocalContext.current as Activity
    val scope = rememberCoroutineScope()
    var rows by remember { mutableStateOf<List<DeskCollection>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val printer = remember { PrintState() }

    fun load() {
        scope.launch {
            error = null
            try {
                rows = ApiClient.service.collections(company).data
            } catch (e: Exception) {
                error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            }
        }
    }
    LaunchedEffect(Unit) { load() }

    fun print(c: DeskCollection) {
        val key = "c${c.id}"
        scope.launch {
            printer.busyKey = key
            printer.message = key to s.printingMsg
            printer.message = key to (PrinterManager.printReceipt(activity, ReceiptRef.Collection(c.id), s) ?: s.printSuccessMsg)
            printer.busyKey = null
        }
    }

    LazyColumn(contentPadding = PaddingValues(start = 16.dp, end = 16.dp, bottom = 24.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        val list = rows
        error?.let { item { MessageBox(it, onRetry = { load() }, retryLabel = s.retry) } }
        if (list == null && error == null) item { LoadingBox() }
        if (list != null) {
            item {
                DeskCard {
                    Row(Modifier.fillMaxWidth().padding(12.dp)) {
                        TotalCell(s.billCount(list.size), Format.tk(list.sumOf { it.paid }), Good, Modifier.weight(1f))
                        TotalCell(s.discountLabel, Format.tk(list.sumOf { it.discount }), Ink, Modifier.weight(1f))
                    }
                }
            }
            if (list.isEmpty()) item { EmptyText(s.noBillsToday) }
            items(list, key = { it.id }) { c ->
                val key = "c${c.id}"
                BillRow(
                    title = c.customerName ?: "", sub = listOfNotNull(c.customerId?.let { "ID $it" }, c.username).joinToString(" · "),
                    meta = listOfNotNull(Format.time(c.createdAt), c.method).filter { it.isNotBlank() }.joinToString(" · "),
                    paid = c.paid, discount = c.discount, due = null, receiptNo = c.receiptNo ?: "D-${c.id}",
                    status = null, s = s, printing = printer.busyKey == key,
                    message = printer.message?.takeIf { it.first == key }?.second, canPrint = true, onPrint = { print(c) },
                )
            }
        }
    }
}

@Composable
private fun TotalCell(label: String, value: String, color: Color, modifier: Modifier = Modifier) {
    Column(modifier) {
        Text(label, fontSize = 11.sp, color = Muted, maxLines = 1)
        Text(value, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, color = color, maxLines = 1)
    }
}

@Composable
private fun BillRow(
    title: String, sub: String, meta: String, paid: Double, discount: Double, due: Double?, receiptNo: String,
    status: String?, s: AppStrings, printing: Boolean, message: String?, canPrint: Boolean, onPrint: () -> Unit,
) {
    DeskCard {
        Column(Modifier.padding(12.dp)) {
            Row(verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f)) {
                    Text(title, fontSize = 14.sp, fontWeight = FontWeight.Bold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    if (sub.isNotBlank()) Text(sub, fontSize = 12.sp, color = Muted, maxLines = 1)
                    if (meta.isNotBlank()) Text(meta, fontSize = 11.sp, color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text(Format.tk(paid), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, color = Good)
                    if (discount > 0) Text(s.discountShort(Format.amount(discount)), fontSize = 11.sp, color = Muted)
                    if (due != null && due > 0) Text(s.dueShort(Format.amount(due)), fontSize = 11.sp, color = Danger)
                }
            }
            Spacer(Modifier.height(8.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(receiptNo, fontSize = 12.sp, color = PrimaryStrong, fontWeight = FontWeight.SemiBold)
                Spacer(Modifier.width(8.dp))
                when (status) {
                    "approved" -> Pill(s.statusApproved, Good)
                    "pending" -> Pill(s.statusPending, Warn)
                    "deleted" -> Pill(s.statusDeleted, Danger)
                }
                Spacer(Modifier.weight(1f))
                if (canPrint) SmallAction(if (printing) s.printingMsg else s.print, Icons.Filled.Print, enabled = !printing) { onPrint() }
            }
            message?.let {
                Text(it, fontSize = 12.sp, color = if (it == s.printSuccessMsg) Good else Warn, fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.padding(top = 6.dp).clickable { })
            }
        }
    }
}
