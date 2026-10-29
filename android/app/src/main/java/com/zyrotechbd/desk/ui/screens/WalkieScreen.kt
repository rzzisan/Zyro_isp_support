package com.zyrotechbd.desk.ui.screens

import android.Manifest
import android.content.Context
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.net.Uri
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Mic
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Stop
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.zyrotechbd.desk.BuildConfig
import com.zyrotechbd.desk.core.Format
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.WalkieClip
import com.zyrotechbd.desk.ui.components.Chip
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.EmptyText
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.components.SectionTitle
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Line
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.PrimarySoft
import com.zyrotechbd.desk.ui.theme.PrimaryStrong
import com.zyrotechbd.desk.ui.theme.Subtle
import com.zyrotechbd.desk.walkie.Walkie
import com.zyrotechbd.desk.walkie.WalkieCallActivity
import com.zyrotechbd.desk.walkie.WalkieService
import kotlinx.coroutines.delay

/**
 * The walkie-talkie: on/off, the group's members (online or not, who is talking, a private call), the big
 * push-to-talk button, and the group's earlier transmissions to play again.
 */
@Composable
fun WalkieScreen(companyKey: String) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val st by Walkie.state.collectAsState()
    var micAsked by remember { mutableStateOf(false) }
    val askMic = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        micAsked = true
        // the running service may use the microphone only once it is refreshed with the permission, app in front
        if (ok && WalkieService.running) WalkieService.start(context, companyKey, foreground = true)
    }
    LaunchedEffect(companyKey) {
        Walkie.load(companyKey)
        if (!Walkie.hasMic(context)) askMic.launch(Manifest.permission.RECORD_AUDIO)
        else if (WalkieService.running) WalkieService.start(context, companyKey, foreground = true)
    }

    val group = st.group
    Column(Modifier.fillMaxSize()) {
        LazyColumn(Modifier.weight(1f).fillMaxWidth().padding(horizontal = 16.dp)) {
            item {
                Spacer(Modifier.height(16.dp))
                DeskCard {
                    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(s.walkieSwitch, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = Ink)
                            Text(when {
                                !st.on -> s.walkieOffHint
                                st.muted -> s.walkieMuted
                                !st.connected -> st.error ?: s.walkieConnecting
                                else -> s.walkieListening + (st.group?.name?.let { " · $it" } ?: "")
                            }, fontSize = 13.sp, color = if (st.on && st.connected) Good else Muted)
                        }
                        Switch(checked = st.on, onCheckedChange = { on ->
                            if (on) {
                                WalkieService.start(context, companyKey, foreground = true)
                            } else {
                                Walkie.markOff()
                                WalkieService.stop(context)
                            }
                        }, colors = SwitchDefaults.colors(checkedTrackColor = Primary))
                    }
                }
                if (st.groups.size > 1) {
                    Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(top = 12.dp),
                        horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        st.groups.forEach { g -> Chip(g.name, g.id == st.groupId) { Walkie.switchGroup(g.id) } }
                    }
                }
                if (st.on && st.groups.isEmpty() && st.error != null) {
                    Spacer(Modifier.height(12.dp))
                    EmptyText(st.error ?: s.walkieNoGroup)
                }
            }
            if (group != null) {
                item { SectionTitle("${s.walkieMembers} (${group.members.count { it.id in st.online }}/${group.members.size} ${s.walkieOnline})",
                    Modifier.padding(top = 16.dp)) }
                item {
                    DeskCard {
                        group.members.sortedByDescending { it.id in st.online }.forEachIndexed { i, m ->
                            if (i > 0) HorizontalDivider(color = Line)
                            val online = st.on && st.connected && m.id in st.online
                            val talking = m.id == st.talker || (m.id == st.me && st.onAir)
                            Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                                Box(Modifier.size(10.dp).background(if (online) Good else Color(0xFFD1D5DB), CircleShape))
                                Spacer(Modifier.width(12.dp))
                                Column(Modifier.weight(1f)) {
                                    Text(if (m.id == st.me) "${m.name} (${s.walkieYou})" else m.name, fontSize = 15.sp, color = Ink,
                                        maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    Text(if (talking) s.walkieTalkingNow else if (online) s.walkieOnline else s.walkieOffline,
                                        fontSize = 12.sp, color = if (talking) Warn else Muted)
                                }
                                if (m.id != st.me) {
                                    IconButton(onClick = {
                                        context.startActivity(WalkieCallActivity.outgoing(context, companyKey, m.id, m.name))
                                    }) { Icon(Icons.Filled.Call, contentDescription = s.walkieCall, tint = PrimaryStrong) }
                                }
                            }
                        }
                    }
                }
                item { SectionTitle(s.walkieRecordings, Modifier.padding(top = 16.dp)) }
                item { Recordings(companyKey, group.id, st.talker) }
                item { Spacer(Modifier.height(16.dp)) }
            }
        }
        if (group != null) TalkButton(st, micMissing = micAsked && !Walkie.hasMic(context))
    }
}

/** The big push-to-talk button: hold to talk, release to stop; busy while someone else talks. */
@Composable
private fun TalkButton(st: Walkie.State, micMissing: Boolean) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val enabled = st.on && st.connected && !st.muted
    val busy = st.talker != null && !st.talking
    val color = when {
        st.onAir -> Color(0xFFDC2626)
        st.talking -> Warn
        busy || !enabled -> Color(0xFF9CA3AF)
        else -> Primary
    }
    val label = when {
        micMissing -> s.walkieMicNeeded
        !st.on -> s.walkieOffHint
        st.muted -> s.walkieMuted
        !st.connected -> s.walkieConnecting
        st.onAir -> s.walkieOnAir
        st.talking -> s.walkieWait
        busy -> s.walkieTalks(st.name(st.talker) ?: "")
        else -> s.walkieHold
    }
    Column(Modifier.fillMaxWidth().background(Subtle).padding(vertical = 16.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.size(128.dp).background(color, CircleShape).border(6.dp, PrimarySoft, CircleShape)
            .pointerInput(enabled) {
                detectTapGestures(onPress = {
                    if (enabled && Walkie.press()) {
                        tryAwaitRelease()
                        Walkie.release()
                    } else buzz(context)
                })
            }, contentAlignment = Alignment.Center) {
            Icon(Icons.Filled.Mic, contentDescription = s.walkieHold, tint = Color.White, modifier = Modifier.size(56.dp))
        }
        Spacer(Modifier.height(10.dp))
        Text(label, fontSize = 14.sp, color = Ink, textAlign = TextAlign.Center, modifier = Modifier.padding(horizontal = 24.dp))
    }
}

private fun buzz(context: Context) {
    val v = context.getSystemService(Vibrator::class.java) ?: return
    runCatching {
        if (Build.VERSION.SDK_INT >= 26) v.vibrate(VibrationEffect.createOneShot(80, VibrationEffect.DEFAULT_AMPLITUDE))
    }
}

/** The group's last transmissions (newest first); tap to play. Refreshes after each one ends. */
@Composable
private fun Recordings(company: String, groupId: Long, talker: Long?) {
    val s = LocalStrings.current
    val context = LocalContext.current
    var clips by remember(groupId) { mutableStateOf<List<WalkieClip>?>(null) }
    var playing by remember { mutableStateOf<Long?>(null) }
    val player = remember { mutableStateOf<MediaPlayer?>(null) }
    LaunchedEffect(groupId, talker) {
        if (talker == null) delay(2500)   // the recorder saves a transmission a moment after it ends
        runCatching { ApiClient.service.walkieClips(company, groupId) }.getOrNull()?.let { clips = it.data }
    }
    DisposableEffect(Unit) { onDispose { player.value?.release() } }
    val list = clips
    if (list == null) return
    if (list.isEmpty()) return EmptyText(s.walkieNoRecordings)
    DeskCard {
        list.take(30).forEachIndexed { i, c ->
            if (i > 0) HorizontalDivider(color = Line)
            Row(Modifier.fillMaxWidth().clickable {
                player.value?.release()
                player.value = null
                if (playing == c.id) {
                    playing = null
                    return@clickable
                }
                playing = c.id
                player.value = MediaPlayer().apply {
                    setAudioAttributes(AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_MEDIA)
                        .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH).build())
                    setDataSource(context, Uri.parse(BuildConfig.API_BASE_URL + "c/$company/walkie/clips/${c.id}"),
                        mapOf("Authorization" to "Bearer ${SessionStore.token()}"))
                    setOnPreparedListener { it.start() }
                    setOnCompletionListener { playing = null }
                    setOnErrorListener { _, _, _ -> playing = null; true }
                    prepareAsync()
                }
            }.padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(if (playing == c.id) Icons.Filled.Stop else Icons.Filled.PlayArrow, contentDescription = null, tint = PrimaryStrong)
                Spacer(Modifier.width(12.dp))
                Text(c.user ?: "—", fontSize = 15.sp, color = Ink, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text("${Format.shortTime(c.startedAt, "")} · ${"%.0f".format(c.seconds)}s", fontSize = 12.sp, color = Muted)
            }
        }
    }
}
