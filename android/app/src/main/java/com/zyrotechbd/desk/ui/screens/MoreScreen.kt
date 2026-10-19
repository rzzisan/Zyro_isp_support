package com.zyrotechbd.desk.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.automirrored.filled.OpenInNew
import androidx.compose.material.icons.filled.Business
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Language
import androidx.compose.material.icons.filled.Print
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.zyrotechbd.desk.BuildConfig
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.CompanyInfo
import com.zyrotechbd.desk.ui.Tab
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.SectionTitle
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Line
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.PrimarySoft
import com.zyrotechbd.desk.ui.theme.PrimaryStrong

/** The account tab: who is signed in, printers, company switch, language, the panel in a browser, sign out. */
@Composable
fun MoreScreen(
    company: CompanyInfo?,
    companies: List<CompanyInfo>,
    extra: List<Tab>,
    onOpen: (String) -> Unit,
    onPrinters: () -> Unit,
    onSwitchCompany: (String) -> Unit,
    onOpenDesk: () -> Unit,
    onToggleLang: () -> Unit,
    onLogout: () -> Unit,
) {
    val s = LocalStrings.current
    val name = SessionStore.userName().ifBlank { "User" }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
        DeskCard {
            Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(44.dp).background(PrimarySoft, CircleShape), contentAlignment = Alignment.Center) {
                    Text(name.first().uppercase(), color = PrimaryStrong, fontWeight = FontWeight.SemiBold, fontSize = 18.sp)
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(name, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = Ink, maxLines = 1,
                        overflow = TextOverflow.Ellipsis)
                    Text(SessionStore.userEmail(), fontSize = 13.sp, color = Muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text("${company?.name ?: ""} · ${s.roleName(company?.role)}", fontSize = 12.sp, color = Muted,
                        maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
        }
        Spacer(Modifier.height(16.dp))
        if (extra.isNotEmpty()) {
            DeskCard {
                extra.forEachIndexed { i, t ->
                    if (i > 0) HorizontalDivider(color = Line)
                    MoreRow(t.label, t.icon) { onOpen(t.route) }
                }
            }
            Spacer(Modifier.height(16.dp))
        }
        DeskCard {
            MoreRow(s.navPrinters, Icons.Filled.Print, onClick = onPrinters)
            HorizontalDivider(color = Line)
            MoreRow(s.language, Icons.Filled.Language, value = s.langToggleLabel, onClick = onToggleLang)
            HorizontalDivider(color = Line)
            MoreRow(s.openDesk, Icons.AutoMirrored.Filled.OpenInNew, chevron = false, onClick = onOpenDesk)
        }
        if (companies.size > 1) {
            Spacer(Modifier.height(16.dp))
            SectionTitle(s.companies)
            DeskCard {
                companies.forEachIndexed { i, c ->
                    if (i > 0) HorizontalDivider(color = Line)
                    val current = c.slug == company?.slug
                    MoreRow(c.name, if (current) Icons.Filled.CheckCircle else Icons.Filled.Business,
                        tint = if (current) Primary else Muted, chevron = false) { if (!current) onSwitchCompany(c.slug) }
                }
            }
        }
        Spacer(Modifier.height(16.dp))
        DeskCard {
            MoreRow(s.logout, Icons.AutoMirrored.Filled.Logout, tint = Danger, textColor = Danger, chevron = false, onClick = onLogout)
        }
        Spacer(Modifier.height(16.dp))
        Text("Zyro Desk · ${s.version} ${BuildConfig.VERSION_NAME}", fontSize = 12.sp, color = Muted,
            modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
    }
}

@Composable
private fun MoreRow(label: String, icon: ImageVector, value: String? = null, tint: Color = Muted, textColor: Color = Ink,
                    chevron: Boolean = true, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable { onClick() }.padding(horizontal = 16.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(12.dp))
        Text(label, fontSize = 15.sp, color = textColor, modifier = Modifier.weight(1f), maxLines = 1,
            overflow = TextOverflow.Ellipsis)
        if (value != null) Text(value, fontSize = 14.sp, color = Muted)
        if (chevron) Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = Muted)
    }
}
