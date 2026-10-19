package com.zyrotechbd.desk.ui.screens

import android.app.Activity
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowDropDown
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.BillForm
import com.zyrotechbd.desk.data.network.BillRequest
import com.zyrotechbd.desk.data.network.BillResult
import com.zyrotechbd.desk.data.network.Choice
import com.zyrotechbd.desk.data.network.Customer
import com.zyrotechbd.desk.printing.PrinterManager
import com.zyrotechbd.desk.printing.ReceiptRef
import com.zyrotechbd.desk.ui.components.CatvCard
import com.zyrotechbd.desk.ui.components.CatvTextField
import com.zyrotechbd.desk.ui.components.CustomerAvatar
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.components.InfoRow
import com.zyrotechbd.desk.ui.components.LoadingBox
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.PrimaryButton
import com.zyrotechbd.desk.ui.components.ScreenBody
import com.zyrotechbd.desk.ui.components.SmallAction
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.Period

/** "বিল নিন": billing's Receive Bill through the Desk, with the member's own billing login (same rules as the panel). */
@Composable
fun BillFormScreen(navController: NavHostController, company: String, headerId: Long) {
    val s = LocalStrings.current
    val activity = LocalContext.current as Activity
    val scope = rememberCoroutineScope()
    var customer by remember { mutableStateOf<Customer?>(null) }
    var form by remember { mutableStateOf<BillForm?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    var paid by remember { mutableStateOf("") }
    var discount by remember { mutableStateOf("") }
    var methodId by remember { mutableStateOf<String?>(null) }
    var receivedById by remember { mutableStateOf<String?>(null) }
    var trxNo by remember { mutableStateOf("") }
    var billMonth by remember { mutableStateOf("") }
    var remarks by remember { mutableStateOf("") }
    var deskSms by remember { mutableStateOf(false) }
    var nextBillingDate by remember { mutableStateOf(true) }
    var printAfter by remember { mutableStateOf(true) }

    var saving by remember { mutableStateOf(false) }
    var saveError by remember { mutableStateOf<String?>(null) }
    var result by remember { mutableStateOf<BillResult?>(null) }
    var printing by remember { mutableStateOf(false) }
    var printStatus by remember { mutableStateOf<String?>(null) }

    fun print(collectionId: Long) {
        scope.launch {
            printing = true
            printStatus = s.printingMsg
            printStatus = PrinterManager.printReceipt(activity, ReceiptRef.Collection(collectionId), s) ?: s.printSuccessMsg
            printing = false
        }
    }

    fun load() {
        scope.launch {
            error = null
            form = null
            try {
                customer = ApiClient.service.customer(company, headerId).customer
                val f = ApiClient.service.billForm(company, headerId)
                form = f
                paid = if (f.payable > 0) Format.amount(f.payable).replace(",", "") else ""
                discount = ""
                methodId = f.methodDefault ?: f.methods.firstOrNull()?.id
                receivedById = f.receivedByDefault ?: f.receivedBy.firstOrNull()?.id
                billMonth = f.billMonth.orEmpty()
                deskSms = f.smsDefault
            } catch (e: Exception) {
                error = ApiClient.errorMessage(e, s.billFormFailed, s.offline)
            }
        }
    }

    LaunchedEffect(headerId) { load() }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
        ScreenBody {
            customer?.let { c ->
                CatvCard {
                    Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                        CustomerAvatar(c.name ?: "?", c.customerId ?: c.headerId.toString(), size = 44.dp)
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(c.name ?: "", fontSize = 16.sp, fontWeight = FontWeight.Bold, color = Ink)
                            Text("ID ${c.customerId ?: ""}" + (c.mobile?.let { " · $it" } ?: ""), fontSize = 12.sp, color = Muted)
                        }
                    }
                }
                Spacer(Modifier.height(12.dp))
            }

            val res = result
            if (res != null) {
                CatvCard {
                    Column(Modifier.padding(16.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = Good, modifier = Modifier.height(48.dp))
                        Spacer(Modifier.height(6.dp))
                        Text(s.billSaved, fontSize = 16.sp, fontWeight = FontWeight.Bold, color = Ink)
                        Text(s.balanceLeft(Format.amount(res.balance)), fontSize = 13.sp, color = Muted)
                        res.message?.takeIf { it.isNotBlank() }?.let { Text(it, fontSize = 12.sp, color = Muted) }
                        res.sms?.takeIf { it.isNotBlank() }?.let { Text(it, fontSize = 12.sp, color = Muted) }
                        Spacer(Modifier.height(12.dp))
                        res.collectionId?.let { id ->
                            PrimaryButton(s.printReceipt, loading = printing) { print(id) }
                            Spacer(Modifier.height(8.dp))
                        }
                        printStatus?.let {
                            Text(it, fontSize = 13.sp, color = if (it == s.printSuccessMsg) Good else Warn, fontWeight = FontWeight.SemiBold)
                            Spacer(Modifier.height(8.dp))
                        }
                        SmallAction(s.done, Icons.Filled.CheckCircle, Modifier.fillMaxWidth()) { navController.popBackStack() }
                    }
                }
                return@ScreenBody
            }

            if (error != null) {
                MessageBox(error!!, onRetry = { load() }, retryLabel = s.retry)
                return@ScreenBody
            }
            val f = form ?: run {
                LoadingBox()
                Text(s.billFormLoading, fontSize = 12.sp, color = Muted, modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp))
                return@ScreenBody
            }

            CatvCard {
                Column(Modifier.padding(14.dp)) {
                    InfoRow("PPPoE", f.username, bold = true)
                    InfoRow("Billing Status", f.status)
                    InfoRow(s.monthlyBill, Format.tk(f.monthly))
                    InfoRow(s.dueNow, Format.tk(f.payable), valueColor = if (f.payable > 0) Danger else Good, bold = true)
                    InfoRow(s.billMonth, f.billMonth)
                }
            }
            f.blocked?.let { b ->
                Spacer(Modifier.height(8.dp))
                val since = b.since?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(Format.dhaka) }.getOrNull() }
                val text = if (since != null) {
                    val gap = Period.between(since.toLocalDate(), LocalDate.now(Format.dhaka))
                    s.blockedFor(gap.years * 12 + gap.months, gap.days) + " (" + listOfNotNull(Format.dateTime(b.since), b.by, b.profile)
                        .filter { it.isNotBlank() }.joinToString(" · ") + ")"
                } else s.blockedUnknown
                MessageBox(text, color = Danger)
            }
            f.pending.forEach { p ->
                Spacer(Modifier.height(8.dp))
                MessageBox(s.pendingWarning(p.amount?.toString() ?: "", p.date?.toString() ?: "", p.createdBy ?: ""), color = Warn)
            }
            if (f.pending.isEmpty() && f.payable <= 0) {
                Spacer(Modifier.height(8.dp))
                MessageBox(s.noDueInBilling, color = Good)
            }
            Spacer(Modifier.height(12.dp))

            val paidValue = paid.toDoubleOrNull() ?: 0.0
            val discountValue = discount.toDoubleOrNull() ?: 0.0
            val balance = f.payable - discountValue - paidValue
            val remarksRequired = discountValue > 0 || kotlin.math.abs(paidValue + discountValue - f.payable) > 0.001

            CatvCard {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Field(s.paidLabel, Modifier.weight(1f)) {
                            CatvTextField(paid, { paid = it.filter { ch -> ch.isDigit() || ch == '.' } }, "0",
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
                        }
                        Field(s.discountLabel, Modifier.weight(1f)) {
                            CatvTextField(discount, { discount = it.filter { ch -> ch.isDigit() || ch == '.' } }, "0",
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
                        }
                    }
                    Text(s.balanceAfter(Format.amount(f.payable), Format.amount(discountValue), Format.amount(paidValue), Format.amount(balance)),
                        fontSize = 12.sp, color = if (balance < -0.001) Danger else Muted)
                    Field(s.methodLabel) { Picker(f.methods, methodId) { methodId = it } }
                    Field("Received By") { Picker(f.receivedBy, receivedById) { receivedById = it } }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Field(s.trxLabel, Modifier.weight(1f)) {
                            CatvTextField(trxNo, { trxNo = it.filter { ch -> !ch.isWhitespace() } }, "")
                        }
                        Field(s.billMonth, Modifier.weight(1f)) { CatvTextField(billMonth, { billMonth = it }, "") }
                    }
                    Field(s.remarksLabel + if (remarksRequired) " *" else "") {
                        CatvTextField(remarks, { remarks = it.take(500) }, s.remarksHint)
                    }
                    Toggle(s.smsToCustomer, deskSms, enabled = f.smsReady, hint = if (f.smsReady) null else s.smsNotReady) { deskSms = it }
                    Toggle(s.nextBillingDate, nextBillingDate) { nextBillingDate = it }
                    Toggle(s.printAfterSave, printAfter) { printAfter = it }
                }
            }
            Spacer(Modifier.height(12.dp))
            saveError?.let {
                MessageBox(it)
                Spacer(Modifier.height(8.dp))
            }
            val blockedByPending = f.pending.isNotEmpty()
            PrimaryButton(s.saveBill, enabled = !blockedByPending, loading = saving) {
                saveError = when {
                    paidValue <= 0 && discountValue <= 0 -> s.enterAmount
                    balance < -0.001 -> s.overPayment
                    methodId == null -> s.pickMethod
                    receivedById == null -> s.pickReceivedBy
                    remarksRequired && remarks.isBlank() -> s.remarksRequired
                    else -> null
                }
                if (saveError != null) return@PrimaryButton
                scope.launch {
                    saving = true
                    try {
                        val r = ApiClient.service.collectBill(company, headerId, BillRequest(
                            paid = paidValue, discount = discountValue, methodId = methodId!!, receivedById = receivedById!!,
                            trxNo = trxNo, billMonth = billMonth.ifBlank { null }, remarks = remarks.trim(),
                            expectedPayable = f.payable, nextBillingDate = nextBillingDate, deskSms = deskSms && f.smsReady,
                        ))
                        result = r
                        if (printAfter) r.collectionId?.let { print(it) }
                    } catch (e: Exception) {
                        saveError = ApiClient.errorMessage(e, s.billNotSaved, s.offline)
                    } finally {
                        saving = false
                    }
                }
            }
            if (blockedByPending) {
                Text(s.pendingBlocksHint, fontSize = 12.sp, color = Warn, modifier = Modifier.padding(top = 6.dp))
            }
            Spacer(Modifier.height(24.dp))
            }
    }
}

@Composable
private fun Field(label: String, modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    Column(modifier) {
        Text(label, fontSize = 12.sp, color = Muted, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(start = 6.dp, bottom = 4.dp))
        content()
    }
}

@Composable
private fun Picker(options: List<Choice>, selected: String?, onPick: (String) -> Unit) {
    var open by remember { mutableStateOf(false) }
    Box {
        CatvCard(Modifier.fillMaxWidth().clickable { open = true }) {
            Row(Modifier.padding(horizontal = 16.dp, vertical = 11.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(options.firstOrNull { it.id == selected }?.name ?: "—", fontSize = 15.sp, color = Ink, modifier = Modifier.weight(1f))
                Icon(Icons.Filled.ArrowDropDown, contentDescription = null, tint = Muted)
            }
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            options.forEach { o ->
                DropdownMenuItem(text = { Text(o.name) }, onClick = { onPick(o.id); open = false })
            }
        }
    }
}

@Composable
private fun Toggle(label: String, checked: Boolean, enabled: Boolean = true, hint: String? = null, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(label, fontSize = 14.sp, color = if (enabled) Ink else Muted)
            hint?.let { Text(it, fontSize = 11.sp, color = Muted) }
        }
        Switch(checked = checked && enabled, onCheckedChange = onChange, enabled = enabled,
            colors = SwitchDefaults.colors(checkedTrackColor = Primary))
    }
}
