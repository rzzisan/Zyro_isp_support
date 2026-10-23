package com.zyrotechbd.desk.ui.screens

import android.Manifest
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.DutyRequest
import com.zyrotechbd.desk.location.FieldTracker
import com.zyrotechbd.desk.ui.components.Danger
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.components.PrimaryButton
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Surface
import kotlinx.coroutines.launch

/**
 * "আমার কাজ" top card: the location notice (agree once), the duty switch, and whether location is going now. The
 * technician always sees when their location is sent; the service's notification shows it too.
 */
@Composable
fun FieldLocationCard(company: String) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val st by FieldTracker.state.collectAsState()
    var permitted by remember { mutableStateOf(FieldTracker.hasPermission(context)) }
    var notice by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(company) { FieldTracker.refresh(context, company) }
    val ask = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        permitted = FieldTracker.hasPermission(context)
        scope.launch { FieldTracker.refresh(context, company) }
    }
    fun askPermission() = ask.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))

    val state = st ?: return
    DeskCard {
        Column(Modifier.padding(horizontal = 14.dp, vertical = 10.dp)) {
            if (!state.consent) {
                Text(s.locNeedConsent, fontSize = 13.sp, color = Ink)
                Spacer(Modifier.height(8.dp))
                PrimaryButton(s.locReadNotice) { notice = true }
            } else {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(s.dutyLabel, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, color = Ink)
                        Text(
                            when {
                                state.jobRunning -> s.dutyJobRunning
                                state.onDuty -> s.dutyOn
                                else -> s.dutyOff
                            },
                            fontSize = 12.sp, color = if (state.track && permitted) Good else Muted,
                        )
                    }
                    Spacer(Modifier.width(8.dp))
                    Switch(
                        checked = state.onDuty,
                        enabled = !busy,
                        colors = SwitchDefaults.colors(checkedTrackColor = Good),
                        onCheckedChange = { on ->
                            if (on && !permitted) askPermission()
                            busy = true
                            scope.launch {
                                try {
                                    FieldTracker.apply(context, company, ApiClient.service.fieldDuty(company, DutyRequest(on)))
                                    error = null
                                } catch (e: Exception) {
                                    error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
                                } finally {
                                    busy = false
                                }
                            }
                        },
                    )
                }
                if (!permitted) {
                    Spacer(Modifier.height(6.dp))
                    Text(s.locPermission, fontSize = 12.sp, color = Warn)
                    TextButton(onClick = { askPermission() }) { Text(s.locAllow) }
                }
            }
            error?.let { Text(it, fontSize = 12.sp, color = Danger) }
        }
    }
    Spacer(Modifier.height(8.dp))

    if (notice) AlertDialog(
        onDismissRequest = { if (!busy) notice = false },
        containerColor = Surface,
        title = { Text(s.locNoticeTitle, fontSize = 17.sp, fontWeight = FontWeight.SemiBold) },
        text = { Text(s.locNotice, fontSize = 14.sp, color = Ink, modifier = Modifier.verticalScroll(rememberScrollState())) },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                busy = true
                scope.launch {
                    try {
                        FieldTracker.apply(context, company, ApiClient.service.fieldConsent(company))
                        notice = false
                        error = null
                        if (!permitted) askPermission()
                    } catch (e: Exception) {
                        error = ApiClient.errorMessage(e, s.loadFailed, s.offline)
                        notice = false
                    } finally {
                        busy = false
                    }
                }
            }) { Text(s.locAgree) }
        },
        dismissButton = { TextButton(onClick = { notice = false }) { Text(s.locNotNow) } },
    )
}
