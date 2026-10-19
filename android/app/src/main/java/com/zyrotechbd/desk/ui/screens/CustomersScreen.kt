package com.zyrotechbd.desk.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.Customer
import com.zyrotechbd.desk.ui.components.CatvSurface
import com.zyrotechbd.desk.ui.components.CatvTextField
import com.zyrotechbd.desk.ui.components.Chip
import com.zyrotechbd.desk.ui.components.CustomerAvatar
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.EmptyText
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.components.LoadingBox
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.Pill
import com.zyrotechbd.desk.ui.navigation.Routes
import com.zyrotechbd.desk.ui.strings.AppStrings
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import kotlinx.coroutines.delay

/**
 * Customer search (ID, PPPoE, name or mobile, like the panel's header search) and, with no search, the list with
 * filters. In [collect] mode a tap goes straight to the bill form.
 */
@Composable
fun CustomersScreen(navController: NavHostController, company: String, collect: Boolean) {
    val s = LocalStrings.current
    var query by rememberSaveable { mutableStateOf("") }
    var dueOnly by rememberSaveable { mutableStateOf(collect) }
    var zone by rememberSaveable { mutableStateOf<String?>(null) }
    var zones by remember { mutableStateOf<List<String>>(emptyList()) }
    val rows = remember { mutableStateListOf<Customer>() }
    var nextPage by remember { mutableStateOf<Int?>(null) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var reload by remember { mutableStateOf(0) }
    val listState = rememberLazyListState()

    LaunchedEffect(company) {
        zones = runCatching { ApiClient.service.zones(company).data }.getOrDefault(emptyList())
    }

    // first page whenever the search or a filter changes (typing waits a moment so every key is not a request)
    LaunchedEffect(query, dueOnly, zone, reload) {
        if (query.isNotBlank()) delay(350)
        loading = true
        error = null
        try {
            val q = query.trim()
            val page = if (q.isNotEmpty()) ApiClient.service.customers(company, query = q)
            else ApiClient.service.customers(company, due = if (dueOnly) 1 else null, zone = zone, page = 1)
            rows.clear()
            rows.addAll(page.data)
            nextPage = page.nextPage
            listState.scrollToItem(0)
        } catch (e: Exception) {
            error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
        } finally {
            loading = false
        }
    }

    val nearEnd by remember {
        derivedStateOf {
            val last = listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0
            last >= listState.layoutInfo.totalItemsCount - 5
        }
    }
    LaunchedEffect(nearEnd, nextPage, loading) {
        val page = nextPage ?: return@LaunchedEffect
        if (!nearEnd || loading || query.isNotBlank()) return@LaunchedEffect
        loading = true
        try {
            val res = ApiClient.service.customers(company, due = if (dueOnly) 1 else null, zone = zone, page = page)
            rows.addAll(res.data)
            nextPage = res.nextPage
        } catch (e: Exception) {
            error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
            nextPage = null
        } finally {
            loading = false
        }
    }

    Column(Modifier.fillMaxSize()) {
        Column(Modifier.padding(start = 16.dp, end = 16.dp, top = 12.dp)) {
            if (collect) {
                Text(s.collectHint, fontSize = 13.sp, color = Muted)
                Spacer(Modifier.height(8.dp))
            }
            CatvTextField(value = query, onValueChange = { query = it }, placeholder = s.searchCustomer,
                modifier = Modifier.fillMaxWidth())
            if (query.isBlank()) {
                Spacer(Modifier.height(8.dp))
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    item { Chip(s.dueOnly, dueOnly) { dueOnly = !dueOnly } }
                    item { Chip(s.allZones, zone == null) { zone = null } }
                    items(zones) { z -> Chip(z, zone == z) { zone = if (zone == z) null else z } }
                }
            }
            Spacer(Modifier.height(8.dp))
            if (error != null) {
                MessageBox(error!!, onRetry = { reload++ }, retryLabel = s.retry)
                Spacer(Modifier.height(8.dp))
            }
        }
        LazyColumn(
            state = listState,
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
            modifier = Modifier.fillMaxSize()
        ) {
            items(rows, key = { it.headerId }) { c ->
                CustomerRow(c, s) {
                    navController.navigate(if (collect) Routes.billForm(c.headerId) else Routes.customerDetail(c.headerId))
                }
            }
            if (loading) item { LoadingBox() }
            if (!loading && rows.isEmpty() && error == null) item { EmptyText(s.noCustomers) }
        }
    }
}

@Composable
private fun CustomerRow(c: Customer, s: AppStrings, onClick: () -> Unit) {
    CatvSurface(modifier = Modifier.clickable { onClick() }) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Box {
                CustomerAvatar(c.name ?: "?", c.customerId ?: c.headerId.toString(), size = 42.dp)
                Box(
                    Modifier.align(Alignment.BottomEnd).size(12.dp).clip(CircleShape).background(Color.White).padding(2.dp)
                ) {
                    Box(Modifier.fillMaxSize().clip(CircleShape).background(if (c.online) Good else Color(0xFFBDB6AA)))
                }
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(c.name ?: "", fontSize = 15.sp, fontWeight = FontWeight.SemiBold, color = Ink, maxLines = 1,
                        overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                    if (c.isLeft == true) {
                        Spacer(Modifier.width(6.dp))
                        Pill("LEFT", Color(0xFF7B8496))
                    } else if (c.disabled == true) {
                        Spacer(Modifier.width(6.dp))
                        Pill(s.lineOff, Danger)
                    }
                }
                Text(listOfNotNull("ID " + (c.customerId ?: ""), c.username).joinToString(" · "), fontSize = 12.sp,
                    color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                val place = c.place()
                if (place.isNotEmpty()) Text(place, fontSize = 11.sp, color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            Spacer(Modifier.width(8.dp))
            Column(horizontalAlignment = Alignment.End) {
                val due = c.due ?: 0.0
                Text(if (due > 0) Format.tk(due) else s.noDue, fontSize = 14.sp, fontWeight = FontWeight.Bold,
                    color = if (due > 0) Danger else Good)
                if (c.monthlyBill != null) Text(s.perMonth(Format.amount(c.monthlyBill)), fontSize = 11.sp, color = Muted)
            }
        }
    }
}
