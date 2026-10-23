package com.zyrotechbd.desk.ui

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.consumeWindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.ConfirmationNumber
import androidx.compose.material.icons.filled.Engineering
import androidx.compose.material.icons.filled.Forum
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.MoreHoriz
import androidx.compose.material.icons.filled.People
import androidx.compose.material.icons.filled.ReceiptLong
import androidx.compose.material.icons.filled.PhoneInTalk
import androidx.compose.material.icons.filled.Wifi
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.TextButton
import kotlinx.coroutines.launch
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.zyrotechbd.desk.BuildConfig
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.location.FieldTracker
import com.zyrotechbd.desk.data.network.CompanyInfo
import com.zyrotechbd.desk.push.Push
import com.zyrotechbd.desk.core.Badges
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.theme.Primary
import androidx.compose.ui.graphics.Color
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.withTimeoutOrNull
import androidx.core.content.ContextCompat
import com.zyrotechbd.desk.ui.navigation.AppNavGraph
import com.zyrotechbd.desk.ui.navigation.Routes
import com.zyrotechbd.desk.ui.screens.MoreScreen
import com.zyrotechbd.desk.ui.strings.AppStrings
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Background
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Line
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.PrimarySoft
import com.zyrotechbd.desk.ui.theme.PrimaryStrong
import com.zyrotechbd.desk.ui.theme.Surface

data class Tab(val route: String, val label: String, val icon: ImageVector)

/**
 * The screens this member may open in this company (the same menu permissions as the Desk panel), most used first.
 * The first four sit in the bottom bar next to "More"; the rest are listed on the More screen.
 */
private fun screensFor(company: CompanyInfo?, s: AppStrings): List<Tab> = buildList {
    // a field technician opens on their jobs, in the dashboard's place
    if (company?.can("field_jobs") == true) add(Tab(Routes.MyJobs, s.navMyJobs, Icons.Filled.Engineering))
    else add(Tab(Routes.Dashboard, s.navDashboard, Icons.Filled.Home))
    if (company?.can("inbox") == true) add(Tab(Routes.Inbox, s.navInbox, Icons.Filled.Forum))
    if (company?.can("tickets") == true) add(Tab(Routes.Tickets, s.navTickets, Icons.Filled.ConfirmationNumber))
    if (company?.can("customers") == true || company?.can("bills") == true) {
        add(Tab(Routes.Customers, s.navCustomers, Icons.Filled.People))
    }
    if (company?.can("bills") == true) add(Tab(Routes.Bills, s.navBillsShort, Icons.Filled.ReceiptLong))
    if (company?.can("monitoring") == true) add(Tab(Routes.Monitoring, s.navMonitoring, Icons.Filled.Wifi))
    if (company?.can("calls") == true) add(Tab(Routes.Calls, s.navCalls, Icons.Filled.PhoneInTalk))
}

private fun tabsFor(company: CompanyInfo?, s: AppStrings): List<Tab> =
    screensFor(company, s).take(4) + Tab(Routes.More, s.navMore, Icons.Filled.MoreHoriz)

private fun titleFor(route: String?, s: AppStrings): String? = when (route) {
    Routes.Dashboard -> null
    Routes.Customers -> s.navCustomers
    Routes.Collect -> s.navCollect
    Routes.Bills -> s.navBills
    Routes.Printers -> s.navPrinters
    Routes.Password -> s.changePassword
    Routes.More -> s.navMore
    Routes.Inbox -> s.navInbox
    Routes.Monitoring -> s.navMonitoring
    Routes.Calls -> s.navCalls
    Routes.Call -> s.titleCall
    Routes.Chat -> s.titleChat
    Routes.Tickets -> s.navTickets
    Routes.Ticket -> s.titleTicket
    Routes.MyJobs -> s.navMyJobs
    Routes.Job -> s.titleJob
    Routes.NewTicket -> s.titleNewTicket
    Routes.CustomerDetail -> s.titleCustomer
    Routes.BillForm -> s.titleBillForm
    else -> null
}

@Composable
fun MainAppShell(onLogout: () -> Unit, onToggleLang: () -> Unit) {
    val s = LocalStrings.current
    val context = LocalContext.current
    var companies by remember { mutableStateOf(SessionStore.companies()) }
    var company by remember { mutableStateOf(SessionStore.company()) }

    // menus or companies may have changed in the panel since the last sign-in
    var needPrivacy by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        runCatching { ApiClient.service.me() }.getOrNull()?.let { me ->
            needPrivacy = !me.user.privacyAccepted
            SessionStore.save(null, me.user.name, me.user.email, me.companies)
            companies = SessionStore.companies()
            company = SessionStore.company()
        }
        Push.register()
    }

    // Android 13+ asks before an app may show notifications; ask once the member is signed in
    val askNotify = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {}
    LaunchedEffect(Unit) {
        if (Push.enabled && Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            askNotify.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }
    // signed in before the policy came: accept it (or sign out) before going on
    if (needPrivacy) PrivacyGate(onAccepted = { needPrivacy = false }, onLogout = onLogout)
    val pushOpen by Push.open.collectAsState()
    val waitingChats by Badges.waitingChats.collectAsState()
    val openTickets by Badges.openTickets.collectAsState()

    val companyKey = company?.slug ?: ""
    key(companyKey) {
        val navController = rememberNavController()
        val backStack by navController.currentBackStackEntryAsState()
        val currentRoute = backStack?.destination?.route
        val tabs = tabsFor(company, s)
        val isTab = currentRoute == null || tabs.any { it.route == currentRoute }

        // a field technician: location during duty and work, as the privacy policy accepted at sign-in says
        if (company?.can("field_jobs") == true) FieldLocationSetup(companyKey)

        // tab counts: on start, every few minutes, and right after a push
        LaunchedEffect(companyKey) {
            while (companyKey.isNotEmpty()) {
                runCatching { ApiClient.service.dashboard(companyKey) }.getOrNull()?.let { d ->
                    d.inbox?.let { Badges.waitingChats.value = it.waiting }
                    d.tickets?.let { Badges.openTickets.value = it.open }
                }
                withTimeoutOrNull(180_000) { Push.events.first() }
                delay(2_000)  // let a burst of messages settle
            }
        }

        // a tapped chat notification: switch to its company first (this block then starts over), then open the chat
        LaunchedEffect(pushOpen) {
            val p = pushOpen ?: return@LaunchedEffect
            if (p.company.isNotEmpty() && p.company != companyKey && companies.any { it.slug == p.company }) {
                SessionStore.selectCompany(p.company)
                company = SessionStore.company()
                return@LaunchedEffect
            }
            navController.navigate(Routes.chat(p.chatId)) { launchSingleTop = true }
            Push.open.value = null
        }

        fun openTab(route: String) {
            navController.navigate(route) {
                popUpTo(navController.graph.findStartDestination().id) { saveState = true }
                launchSingleTop = true
                restoreState = true
            }
        }

        Scaffold(
            modifier = Modifier.fillMaxSize(),
            containerColor = Background,
            topBar = {
                Column(Modifier.fillMaxWidth().background(Surface).statusBarsPadding()) {
                    Row(Modifier.fillMaxWidth().height(56.dp).padding(end = 16.dp), verticalAlignment = Alignment.CenterVertically) {
                        if (isTab) {
                            Spacer(Modifier.width(16.dp))
                        } else {
                            IconButton(onClick = { navController.popBackStack() }) {
                                Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = s.back, tint = Ink)
                            }
                        }
                        Column(Modifier.weight(1f)) {
                            Text(titleFor(currentRoute, s) ?: (company?.name ?: "Zyro Desk"), fontSize = 17.sp,
                                fontWeight = FontWeight.SemiBold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            if (currentRoute == null || currentRoute == Routes.Dashboard) {
                                Text("Zyro Desk", fontSize = 12.sp, color = Muted)
                            }
                        }
                    }
                    HorizontalDivider(color = Line)
                }
            },
            bottomBar = {
                if (isTab) {
                    Column {
                        HorizontalDivider(color = Line)
                        NavigationBar(containerColor = Surface, tonalElevation = 0.dp) {
                            tabs.forEach { tab ->
                                NavigationBarItem(
                                    selected = currentRoute == tab.route,
                                    onClick = { openTab(tab.route) },
                                    icon = {
                                        val count = when (tab.route) {
                                            Routes.Inbox -> waitingChats
                                            Routes.Tickets -> openTickets
                                            else -> 0
                                        }
                                        BadgedBox(badge = {
                                            if (count > 0) Badge(containerColor = if (tab.route == Routes.Inbox) Primary else Warn,
                                                contentColor = Color.White) { Text(if (count > 99) "99+" else count.toString(), fontSize = 10.sp) }
                                        }) { Icon(tab.icon, contentDescription = null) }
                                    },
                                    label = { Text(tab.label, fontSize = 12.sp, maxLines = 1) },
                                    colors = NavigationBarItemDefaults.colors(
                                        selectedIconColor = PrimaryStrong,
                                        selectedTextColor = PrimaryStrong,
                                        indicatorColor = PrimarySoft,
                                        unselectedIconColor = Muted,
                                        unselectedTextColor = Muted,
                                    )
                                )
                            }
                        }
                    }
                }
            }
        ) { padding ->
            // the app draws edge to edge, so adjustResize does not shrink it: make room for the keyboard here
            Box(Modifier.fillMaxSize().padding(padding).consumeWindowInsets(padding).imePadding()) {
                AppNavGraph(navController = navController, companyKey = companyKey, modifier = Modifier.fillMaxSize(),
                    start = if (company?.can("field_jobs") == true) Routes.MyJobs else Routes.Dashboard) {
                    MoreScreen(
                        company = company,
                        companies = companies,
                        extra = screensFor(company, s).drop(4),
                        onOpen = { navController.navigate(it) },
                        onPrinters = { navController.navigate(Routes.Printers) },
                        onPassword = { navController.navigate(Routes.Password) },
                        onSwitchCompany = { slug ->
                            SessionStore.selectCompany(slug)
                            company = SessionStore.company()
                        },
                        onOpenDesk = {
                            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(BuildConfig.DESK_URL + "app/" + companyKey)))
                        },
                        onToggleLang = onToggleLang,
                        onLogout = onLogout,
                    )
                }
            }
        }
    }
}

/** Location permission for a field technician (while using, then "all the time"), and the 15-minute check. */
@Composable
private fun FieldLocationSetup(company: String) {
    val s = LocalStrings.current
    val context = LocalContext.current
    var askBackground by remember { mutableStateOf(false) }
    val background = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {}
    val foreground = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        if (FieldTracker.hasPermission(context) && !FieldTracker.hasBackground(context)) askBackground = true
    }
    LaunchedEffect(company) {
        FieldTracker.schedule(context, company)
        when {
            !FieldTracker.hasPermission(context) ->
                foreground.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
            !FieldTracker.hasBackground(context) -> askBackground = true
        }
    }
    if (askBackground && Build.VERSION.SDK_INT >= 29) AlertDialog(
        onDismissRequest = { askBackground = false },
        containerColor = com.zyrotechbd.desk.ui.theme.Surface,
        title = { Text(s.locBgTitle) },
        text = { Text(s.locBgText) },
        confirmButton = {
            TextButton(onClick = {
                askBackground = false
                background.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
            }) { Text(s.locBgOk) }
        },
    )
}

/** The privacy policy for members signed in before it came: the app waits until it is accepted. */
@Composable
private fun PrivacyGate(onAccepted: () -> Unit, onLogout: () -> Unit) {
    val s = LocalStrings.current
    val scope = rememberCoroutineScope()
    var checked by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = {},
        containerColor = com.zyrotechbd.desk.ui.theme.Surface,
        title = { Text(s.privacyTitle) },
        text = {
            Column {
                Text(s.privacyNeeded)
                Spacer(Modifier.height(8.dp))
                com.zyrotechbd.desk.ui.screens.PrivacyAccept(checked) { checked = it }
            }
        },
        confirmButton = {
            TextButton(enabled = checked && !busy, onClick = {
                busy = true
                scope.launch {
                    if (runCatching { ApiClient.service.acceptPrivacy() }.isSuccess) onAccepted()
                    busy = false
                }
            }) { Text(s.privacyAccept) }
        },
        dismissButton = { TextButton(onClick = onLogout) { Text(s.logout) } },
    )
}
