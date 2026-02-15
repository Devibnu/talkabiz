{{--
    Activation KPI Tracker — JavaScript Helper
    
    Provides ActivationKpi.track(eventType, metadata) for frontend KPI logging.
    Only loaded for authenticated, non-admin users.
    Fire & forget — never blocks UI.
--}}

@auth
@if(!in_array(auth()->user()->role ?? '', ['super_admin', 'superadmin', 'owner']))
<script>
window.ActivationKpi = {
    track: function(eventType, metadata) {
        try {
            var csrfToken = document.querySelector('meta[name="csrf-token"]');
            fetch('{{ url("/api/activation/track") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken ? csrfToken.getAttribute('content') : '',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    event_type: eventType,
                    metadata: metadata || {}
                })
            }).catch(function() {}); // fire & forget
        } catch(e) {
            // Never break UI
        }
    }
};
</script>
@endif
@endauth
