document.addEventListener('wpcf7mailsent', function (event) {
    jQuery.noConflict();
    jQuery.ajax({
        url: rekurencja_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'regenerate_token'
        },
        success: function(data) {
            // Handle the AJAX response
            let response = typeof data === "string" ? JSON.parse(data) : data;
            
            if (response.token) {
                // Replace the old token in the form with the new one
                let tokenElement = document.querySelector('input[name="form_token"]');
                if (tokenElement) {
                    error_log("Token ajax regenerated: " . response.token);
                    tokenElement.value = response.token;
                } else {
                    console.error("Form token element not found.");
                }
            } else {
                console.error(response.error || "Unexpected error occurred.");
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            console.error("AJAX Error:", textStatus, errorThrown);
        }
    });
});