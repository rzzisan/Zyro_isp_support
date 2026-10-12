{{-- Sidebar accordion: on every page only the current page's group is open; opening a group closes the others.
     Runs right after Filament's own collapsedGroups script (SIDEBAR_NAV_END hook), before Alpine reads localStorage. --}}
<script>
    (() => {
        const groups = [...document.querySelectorAll('.fi-sidebar-nav .fi-sidebar-group.fi-collapsible')]
            .filter((g) => g.dataset.groupLabel && !g.dataset.groupLabel.startsWith('sub_navigation_'))
        const labels = groups.map((g) => g.dataset.groupLabel)
        const closed = groups.filter((g) => !g.classList.contains('fi-active')).map((g) => g.dataset.groupLabel)

        localStorage.setItem('collapsedGroups', JSON.stringify(closed))
        groups.forEach((g) => {
            const items = g.querySelector('.fi-sidebar-group-items')
            const open = !closed.includes(g.dataset.groupLabel)
            g.classList.toggle('fi-collapsed', !open)
            if (items) items.style.display = open ? '' : 'none'
        })

        document.addEventListener('alpine:initialized', () => {
            const store = window.Alpine.store('sidebar')
            if (!store || store.zyroAccordion) return
            store.zyroAccordion = true
            store.collapsedGroups = closed
            store.toggleCollapsedGroup = function (group) {
                if (!labels.includes(group)) {
                    this.collapsedGroups = this.collapsedGroups.includes(group)
                        ? this.collapsedGroups.filter((g) => g !== group)
                        : this.collapsedGroups.concat(group)
                    return
                }
                const opening = this.collapsedGroups.includes(group)
                this.collapsedGroups = opening ? labels.filter((g) => g !== group) : labels
            }
        })
    })()
</script>
