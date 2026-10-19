package com.zyrotechbd.desk.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.Choice
import com.zyrotechbd.desk.data.network.Customer
import com.zyrotechbd.desk.data.network.NewTicketRequest
import com.zyrotechbd.desk.data.network.TicketOptions
import com.zyrotechbd.desk.ui.components.CustomerAvatar
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.DeskTextField
import com.zyrotechbd.desk.ui.components.EmptyText
import com.zyrotechbd.desk.ui.components.Field
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.components.LoadingBox
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.MultiPicker
import com.zyrotechbd.desk.ui.components.Picker
import com.zyrotechbd.desk.ui.components.PrimaryButton
import com.zyrotechbd.desk.ui.components.ScreenBody
import com.zyrotechbd.desk.ui.components.Toggle
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Line
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.PrimaryStrong
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * A new billing ticket, like the panel's "নতুন টিকিট": pick the customer (or come with one), problem, priority, number,
 * description, employees. The customer SMS goes from the Desk's own SMS gateway.
 */
@Composable
fun NewTicketScreen(navController: NavHostController, company: String, headerId: Long) {
    val s = LocalStrings.current
    var picked by remember { mutableStateOf<Long?>(headerId.takeIf { it > 0 }) }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
        val id = picked
        if (id == null) {
            CustomerSearch(company) { picked = it.headerId }
        } else {
            TicketForm(navController, company, id, onChangeCustomer = { picked = null })
        }
        Spacer(Modifier.height(24.dp))
    }
}

@Composable
private fun CustomerSearch(company: String, onPick: (Customer) -> Unit) {
    val s = LocalStrings.current
    var query by remember { mutableStateOf("") }
    val rows = remember { mutableStateListOf<Customer>() }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(query) {
        val q = query.trim()
        if (q.length < 2) {
            rows.clear()
            return@LaunchedEffect
        }
        delay(350)
        loading = true
        try {
            val res = ApiClient.service.ticketCustomers(company, q)
            rows.clear()
            rows.addAll(res.data)
            error = null
        } catch (e: Exception) {
            error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
        } finally {
            loading = false
        }
    }

    Text(s.pickCustomer, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, color = Ink)
    Spacer(Modifier.height(8.dp))
    DeskTextField(query, { query = it }, placeholder = s.searchCustomer, modifier = Modifier.fillMaxWidth())
    Spacer(Modifier.height(10.dp))
    error?.let { MessageBox(it) }
    if (rows.isNotEmpty()) {
        DeskCard {
            rows.forEachIndexed { i, c ->
                if (i > 0) HorizontalDivider(color = Line)
                Row(Modifier.fillMaxWidth().clickable { onPick(c) }.padding(horizontal = 14.dp, vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically) {
                    CustomerAvatar(c.name ?: "?", c.customerId ?: c.headerId.toString(), size = 38.dp)
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(c.name ?: "", fontSize = 15.sp, fontWeight = FontWeight.Medium, color = Ink, maxLines = 1,
                            overflow = TextOverflow.Ellipsis)
                        Text(listOfNotNull(c.customerId?.let { "ID $it" }, c.username, c.mobile).joinToString(" · "),
                            fontSize = 12.sp, color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
        }
    } else if (loading) {
        LoadingBox()
    } else if (query.trim().length >= 2 && error == null) {
        EmptyText(s.noCustomers)
    }
}

/** Medium when billing has it, else the first priority. */
private fun defaultPriority(list: List<Choice>): String? =
    (list.firstOrNull { it.name.equals("medium", ignoreCase = true) } ?: list.firstOrNull())?.id

@Composable
private fun TicketForm(navController: NavHostController, company: String, headerId: Long, onChangeCustomer: () -> Unit) {
    val s = LocalStrings.current
    val scope = rememberCoroutineScope()
    var options by remember { mutableStateOf<TicketOptions?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var category by remember { mutableStateOf<String?>(null) }
    var priority by remember { mutableStateOf<String?>(null) }
    var mobile by remember { mutableStateOf("") }
    var comment by remember { mutableStateOf("") }
    var dept by remember { mutableStateOf("") }
    var employees by remember { mutableStateOf(listOf<String>()) }
    var sms by remember { mutableStateOf(false) }
    var saving by remember { mutableStateOf(false) }
    var formError by remember { mutableStateOf<String?>(null) }
    var created by remember { mutableStateOf<String?>(null) }

    fun load() {
        scope.launch {
            error = null
            try {
                val o = ApiClient.service.ticketOptions(company, headerId)
                options = o
                priority = defaultPriority(o.priorities)
                mobile = o.customer?.mobile.orEmpty()
                sms = o.smsOpen.ready && o.smsOpen.on
            } catch (e: Exception) {
                error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            }
        }
    }
    LaunchedEffect(headerId) { load() }

    ScreenBody {
        val o = options
        if (o == null) {
            if (error != null) MessageBox(error!!, onRetry = { load() }, retryLabel = s.retry) else LoadingBox()
            return@ScreenBody
        }
        created?.let { text ->
            DeskCard {
                Column(Modifier.padding(16.dp)) {
                    MessageBox(text, color = Good)
                    Spacer(Modifier.height(12.dp))
                    PrimaryButton(s.done) { navController.popBackStack() }
                }
            }
            return@ScreenBody
        }

        o.customer?.let { c ->
            DeskCard {
                Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                    CustomerAvatar(c.name ?: "?", c.customerId ?: c.headerId.toString(), size = 42.dp)
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(c.name ?: "", fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = Ink)
                        Text(listOfNotNull(c.customerId?.let { "ID $it" }, c.username).joinToString(" · "), fontSize = 12.sp, color = Muted)
                    }
                    Text(s.changeCustomer, color = PrimaryStrong, fontSize = 13.sp, fontWeight = FontWeight.SemiBold,
                        modifier = Modifier.clickable { onChangeCustomer() }.padding(8.dp))
                }
            }
            Spacer(Modifier.height(10.dp))
        }
        o.openTicket?.let {
            MessageBox("${s.openTicketWarn} (#$it)", color = Warn)
            Spacer(Modifier.height(10.dp))
        }

        Field(s.categoryLabel) { Picker(o.categories, category, placeholder = s.pickCategory) { category = it } }
        Spacer(Modifier.height(10.dp))
        Field(s.priorityLabel) { Picker(o.priorities, priority) { priority = it } }
        Spacer(Modifier.height(10.dp))
        Field(s.mobile) {
            DeskTextField(mobile, { mobile = it.take(30) }, modifier = Modifier.fillMaxWidth(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone))
        }
        Spacer(Modifier.height(10.dp))
        Field(s.commentLabel) {
            DeskTextField(comment, { comment = it.take(1900) }, placeholder = s.commentHint,
                modifier = Modifier.fillMaxWidth().heightIn(min = 90.dp), singleLine = false)
        }
        Spacer(Modifier.height(10.dp))
        if (o.departments.isNotEmpty()) {
            Field(s.departmentLabel) { Picker(listOf(Choice("", s.noDepartment)) + o.departments, dept) { dept = it } }
            Spacer(Modifier.height(10.dp))
        }
        Field(s.employeesLabel) {
            MultiPicker(o.employees, employees, title = s.employeesLabel, placeholder = "—", okLabel = s.save,
                cancelLabel = s.cancel) { employees = it }
        }
        Spacer(Modifier.height(12.dp))
        Toggle(s.smsOpen, sms, enabled = o.smsOpen.ready, hint = if (o.smsOpen.ready) null else s.smsOff) { sms = it }
        Spacer(Modifier.height(12.dp))
        formError?.let {
            MessageBox(it)
            Spacer(Modifier.height(10.dp))
        }
        PrimaryButton(s.createTicket, loading = saving, enabled = !saving) {
            formError = when {
                category == null -> s.pickCategory
                mobile.isBlank() -> s.enterMobile
                comment.isBlank() -> s.writeComment
                else -> null
            }
            if (formError != null) return@PrimaryButton
            scope.launch {
                saving = true
                try {
                    val res = ApiClient.service.createTicket(company, NewTicketRequest(
                        headerId = headerId, categoryId = category!!, priority = priority?.toIntOrNull() ?: 2,
                        mobile = mobile.trim(), comment = comment.trim(), deptId = dept.ifEmpty { null },
                        employees = employees, smsClient = sms && o.smsOpen.ready))
                    created = listOfNotNull(s.ticketCreated + (res.complainId?.let { " #$it" } ?: ""), res.sms).joinToString(" · ")
                } catch (e: Exception) {
                    formError = ApiClient.errorMessage(e, s.loadFailed, s.offline)
                } finally {
                    saving = false
                }
            }
        }
    }
}
