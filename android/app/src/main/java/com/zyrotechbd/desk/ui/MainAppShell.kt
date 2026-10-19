package com.zyrotechbd.desk.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
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
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.automirrored.filled.OpenInNew
import androidx.compose.material.icons.filled.Business
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material.icons.filled.Payments
import androidx.compose.material.icons.filled.People
import androidx.compose.material.icons.filled.Print
import androidx.compose.material.icons.filled.ReceiptLong
import androidx.compose.material3.DrawerValue
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ModalDrawerSheet
import androidx.compose.material3.ModalNavigationDrawer
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.rememberDrawerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
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
import com.zyrotechbd.desk.data.network.CompanyInfo
import com.zyrotechbd.desk.ui.navigation.AppNavGraph
import com.zyrotechbd.desk.ui.navigation.Routes
import com.zyrotechbd.desk.ui.strings.AppStrings
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Background
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.PrimaryLight
import com.zyrotechbd.desk.ui.theme.PrimaryStrong
import com.zyrotechbd.desk.ui.theme.Surface
import kotlinx.coroutines.launch

private data class DrawerNavItem(val route: String, val label: String, val icon: ImageVector)

/** Drawer items the member may open in this company (the same menu permissions as the Desk panel). */
private fun menuFor(company: CompanyInfo?, s: AppStrings): List<DrawerNavItem> = buildList {
    add(DrawerNavItem(Routes.Dashboard, s.navDashboard, Icons.Filled.Home))
    if (company?.can("customers") == true || company?.can("bills") == true) {
        add(DrawerNavItem(Routes.Customers, s.navCustomers, Icons.Filled.People))
    }
    if (company?.can("bills") == true) {
        add(DrawerNavItem(Routes.Collect, s.navCollect, Icons.Filled.Payments))
        add(DrawerNavItem(Routes.Bills, s.navBills, Icons.Filled.ReceiptLong))
    }
    add(DrawerNavItem(Routes.Printers, s.navPrinters, Icons.Filled.Print))
}

private fun titleFor(route: String?, s: AppStrings): String? = when (route) {
    Routes.Dashboard -> null
    Routes.Customers -> s.navCustomers
    Routes.Collect -> s.navCollect
    Routes.Bills -> s.navBills
    Routes.Printers -> s.navPrinters
    Routes.CustomerDetail -> s.titleCustomer
    Routes.BillForm -> s.titleBillForm
    else -> null
}

@Composable
fun MainAppShell(onLogout: () -> Unit, onToggleLang: () -> Unit) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val drawerState = rememberDrawerState(DrawerValue.Closed)
    val scope = rememberCoroutineScope()
    var companies by remember { mutableStateOf(SessionStore.companies()) }
    var company by remember { mutableStateOf(SessionStore.company()) }

    // menus or companies may have changed in the panel since the last sign-in
    LaunchedEffect(Unit) {
        runCatching { ApiClient.service.me() }.getOrNull()?.let { me ->
            SessionStore.save(null, me.user.name, me.user.email, me.companies)
            companies = SessionStore.companies()
            company = SessionStore.company()
        }
    }

    val companyKey = company?.slug ?: ""
    key(companyKey) {
        val navController = rememberNavController()
        val backStack by navController.currentBackStackEntryAsState()
        val currentRoute = backStack?.destination?.route
        val isTopLevel = currentRoute == null || menuFor(company, s).any { it.route == currentRoute }

        fun navigateTo(route: String) {
            scope.launch { drawerState.close() }
            navController.navigate(route) {
                popUpTo(navController.graph.findStartDestination().id) { saveState = true }
                launchSingleTop = true
                restoreState = true
            }
        }

        ModalNavigationDrawer(
            drawerState = drawerState,
            gesturesEnabled = isTopLevel,
            drawerContent = {
                ModalDrawerSheet(drawerContainerColor = Surface, modifier = Modifier.width(300.dp)) {
                    DrawerContent(
                        company = company,
                        companies = companies,
                        currentRoute = currentRoute,
                        onNavigate = { navigateTo(it) },
                        onSwitchCompany = { slug ->
                            SessionStore.selectCompany(slug)
                            company = SessionStore.company()
                            scope.launch { drawerState.close() }
                        },
                        onOpenDesk = {
                            context.startActivity(Intent(Intent.ACTION_VIEW,
                                Uri.parse(BuildConfig.DESK_URL + "app/" + companyKey)))
                        },
                        onLogout = onLogout,
                        onClose = { scope.launch { drawerState.close() } }
                    )
                }
            }
        ) {
            Scaffold(
                modifier = Modifier.fillMaxSize(),
                containerColor = Background,
                topBar = {
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .background(Brush.linearGradient(colors = listOf(Primary, PrimaryLight)))
                            .statusBarsPadding()
                            .height(56.dp)
                    ) {
                        Row(
                            modifier = Modifier.fillMaxSize().padding(end = 12.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            if (isTopLevel) {
                                IconButton(onClick = { scope.launch { drawerState.open() } }) {
                                    Icon(Icons.Filled.Menu, contentDescription = s.menu, tint = Color.White)
                                }
                            } else {
                                IconButton(onClick = { navController.popBackStack() }) {
                                    Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = s.back, tint = Color.White)
                                }
                            }
                            Text(
                                titleFor(currentRoute, s) ?: (company?.name ?: "Zyro Desk"),
                                fontSize = 16.sp,
                                fontWeight = FontWeight.SemiBold,
                                color = Color.White,
                                modifier = Modifier.weight(1f),
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis
                            )
                            Box(
                                modifier = Modifier
                                    .clip(CircleShape)
                                    .background(Color.White.copy(alpha = 0.20f))
                                    .clickable { onToggleLang() }
                                    .padding(horizontal = 10.dp, vertical = 5.dp),
                                contentAlignment = Alignment.Center
                            ) {
                                Text(s.langToggleLabel, fontSize = 12.sp, fontWeight = FontWeight.Bold, color = Color.White)
                            }
                        }
                    }
                }
            ) { padding ->
                AppNavGraph(navController = navController, companyKey = companyKey,
                    modifier = Modifier.fillMaxSize().padding(padding))
            }
        }
    }
}

@Composable
private fun DrawerContent(
    company: CompanyInfo?,
    companies: List<CompanyInfo>,
    currentRoute: String?,
    onNavigate: (String) -> Unit,
    onSwitchCompany: (String) -> Unit,
    onOpenDesk: () -> Unit,
    onLogout: () -> Unit,
    onClose: () -> Unit,
) {
    val s = LocalStrings.current
    val userName = SessionStore.userName().ifBlank { "User" }
    val companyName = company?.name ?: "Zyro Desk"

    Column(modifier = Modifier.fillMaxSize().background(Background).verticalScroll(rememberScrollState())) {
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .background(Brush.linearGradient(colors = listOf(Primary, PrimaryLight)))
                .statusBarsPadding()
                .padding(start = 20.dp, end = 12.dp, top = 18.dp, bottom = 18.dp)
        ) {
            Column(verticalArrangement = Arrangement.spacedBy(16.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(
                        modifier = Modifier.size(44.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.22f)),
                        contentAlignment = Alignment.Center
                    ) {
                        Text((companyName.firstOrNull() ?: 'Z').toString().uppercase(), fontWeight = FontWeight.ExtraBold,
                            color = Color.White, fontSize = 18.sp)
                    }
                    Spacer(Modifier.width(12.dp))
                    Column(modifier = Modifier.weight(1f)) {
                        Text(companyName, fontWeight = FontWeight.Bold, color = Color.White, fontSize = 15.sp,
                            maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text("Zyro Desk", color = Color.White.copy(alpha = 0.65f), fontSize = 11.sp)
                    }
                    IconButton(onClick = onClose, modifier = Modifier.size(36.dp)) {
                        Icon(Icons.Filled.Close, contentDescription = s.close, tint = Color.White.copy(alpha = 0.75f),
                            modifier = Modifier.size(20.dp))
                    }
                }
                Row(
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp))
                        .background(Color.White.copy(alpha = 0.14f)).padding(horizontal = 14.dp, vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Box(
                        modifier = Modifier.size(40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.28f)),
                        contentAlignment = Alignment.Center
                    ) {
                        Text((userName.firstOrNull() ?: 'U').toString().uppercase(), fontWeight = FontWeight.ExtraBold,
                            color = Color.White, fontSize = 15.sp)
                    }
                    Spacer(Modifier.width(12.dp))
                    Column(modifier = Modifier.weight(1f)) {
                        Text(userName, fontWeight = FontWeight.SemiBold, color = Color.White, fontSize = 14.sp,
                            maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(s.roleName(company?.role), color = Color.White.copy(alpha = 0.7f), fontSize = 11.sp)
                    }
                }
            }
        }

        Spacer(Modifier.height(6.dp))
        menuFor(company, s).forEach { item ->
            DrawerRow(item.label, item.icon, currentRoute == item.route) { onNavigate(item.route) }
        }
        DrawerRow(s.openDesk, Icons.AutoMirrored.Filled.OpenInNew, false, onOpenDesk)

        if (companies.size > 1) {
            Text(s.companies.uppercase(), fontSize = 10.sp, fontWeight = FontWeight.Bold, color = Muted,
                letterSpacing = 1.2.sp, modifier = Modifier.padding(start = 20.dp, top = 14.dp, bottom = 4.dp))
            companies.forEach { c ->
                DrawerRow(c.name, if (c.slug == company?.slug) Icons.Filled.Check else Icons.Filled.Business,
                    c.slug == company?.slug) { onSwitchCompany(c.slug) }
            }
        }

        Spacer(Modifier.height(12.dp))
        HorizontalDivider(modifier = Modifier.padding(horizontal = 16.dp), color = Color(0xFFE8E4DB))
        Spacer(Modifier.height(4.dp))
        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = 8.dp).clip(RoundedCornerShape(10.dp))
                .clickable { onLogout() }.padding(horizontal = 12.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Box(
                modifier = Modifier.size(32.dp).clip(RoundedCornerShape(8.dp)).background(Color(0xFFFFEEE8)),
                contentAlignment = Alignment.Center
            ) {
                Icon(Icons.AutoMirrored.Filled.Logout, contentDescription = null, tint = Color(0xFFB24734),
                    modifier = Modifier.size(17.dp))
            }
            Spacer(Modifier.width(12.dp))
            Text(s.logout, fontSize = 14.sp, color = Color(0xFFB24734), fontWeight = FontWeight.SemiBold)
        }
        Spacer(Modifier.height(8.dp))
        Text("v" + BuildConfig.VERSION_NAME, fontSize = 11.sp, color = Muted, modifier = Modifier.padding(start = 20.dp))
        Spacer(Modifier.height(20.dp))
    }
}

@Composable
private fun DrawerRow(label: String, icon: ImageVector, isSelected: Boolean, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 8.dp)
            .clip(RoundedCornerShape(10.dp))
            .background(if (isSelected) Color(0xFFE0F5F4) else Color.Transparent)
            .clickable { onClick() }
            .padding(start = 12.dp, end = 12.dp, top = 9.dp, bottom = 9.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Box(
            modifier = Modifier.size(32.dp).clip(RoundedCornerShape(8.dp))
                .background(if (isSelected) PrimaryStrong.copy(alpha = 0.12f) else Color(0xFFF0EDE8)),
            contentAlignment = Alignment.Center
        ) {
            Icon(icon, contentDescription = null, tint = if (isSelected) PrimaryStrong else Muted, modifier = Modifier.size(17.dp))
        }
        Spacer(Modifier.width(12.dp))
        Text(
            label,
            fontSize = 14.sp,
            fontWeight = if (isSelected) FontWeight.SemiBold else FontWeight.Normal,
            color = if (isSelected) PrimaryStrong else Ink,
            modifier = Modifier.weight(1f),
            maxLines = 1,
            overflow = TextOverflow.Ellipsis
        )
    }
}
