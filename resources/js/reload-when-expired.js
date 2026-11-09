/*
 * Reload instead of showing Livewire's "This page has expired" box. The reload gets a fresh
 * page, and staff who ticked "keep me signed in" are signed back in on the way.
 */

function reloadWhenExpired() {
    Livewire.interceptRequest(({ onError }) => {
        onError(({ response, preventDefault }) => {
            if (response.status !== 419) return

            preventDefault()
            location.reload()
        })
    })
}

if (window.Livewire) reloadWhenExpired()
else document.addEventListener('livewire:init', reloadWhenExpired, { once: true })
