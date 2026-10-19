package com.zyrotechbd.desk.ui.components

import androidx.compose.ui.draw.clipToBounds
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.pulltorefresh.PullToRefreshContainer
import androidx.compose.material3.pulltorefresh.rememberPullToRefreshState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.nestedscroll.nestedScroll
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.Surface

/** Pull down to refresh around a scrolling list or column; [onRefresh] runs until the new data is in. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RefreshBox(onRefresh: suspend () -> Unit, modifier: Modifier = Modifier.fillMaxSize(), content: @Composable BoxScope.() -> Unit) {
    val state = rememberPullToRefreshState()
    if (state.isRefreshing) LaunchedEffect(Unit) {
        try {
            onRefresh()
        } finally {
            state.endRefresh()
        }
    }
    Box(modifier.clipToBounds().nestedScroll(state.nestedScrollConnection)) {
        content()
        PullToRefreshContainer(state, Modifier.align(Alignment.TopCenter), containerColor = Surface, contentColor = Primary)
    }
}
