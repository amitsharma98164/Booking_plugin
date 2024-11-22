jQuery(document).ready(function ($) {
    let currentDate = new Date();
    let defaultTimeSlot = '9:00 AM'; // Set your default time slot here

    // Initialize Flatpickr
    const calendar = flatpickr('#calendar-container', {
        inline: true,
        minDate: 'today',
        dateFormat: 'Y-m-d',
        defaultDate: 'today',
        onReady: function (selectedDates, dateStr) {
            fetchTimeSlots(dateStr, true);
        },
        onChange: function (selectedDates, dateStr) {
            fetchTimeSlots(dateStr, false);
        },
    });

    function fetchTimeSlots(dateStr, isInitialLoad) {
        $.ajax({
            url: clinicData.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_time_slots',
                selected_date: dateStr,
                // security: clinicData.nonce
            },
            success: function(response) {
                if (response.success) {
                    const timeSlots = response.data;
                    let slotsHtml = '';

                    timeSlots.forEach(slot => {
                        const isDefault = isInitialLoad && 
                                        isCurrentDate(dateStr) && 
                                        slot === defaultTimeSlot;
                        slotsHtml += `<button class="time-slot ${isDefault ? 'active' : ''}">${slot}</button>`;
                    });

                    $('#time-slots').html(slotsHtml);

                    $('.time-slot').on('click', function () {
                        $('.time-slot').removeClass('active');
                        $(this).addClass('active');
                        openBookingModal($(this).text());
                    });
                } else {
                    $('#time-slots').html('<p>Error loading time slots. Please try again.</p>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Ajax error:', error);
                $('#time-slots').html('<p>Error loading time slots. Please try again.</p>');
            }
        });
    }

    // Helper function to check if a date string represents the current date
    function isCurrentDate(dateStr) {
        const inputDate = new Date(dateStr);
        return inputDate.toDateString() === currentDate.toDateString();
    }

    // Modal actions
    function openBookingModal(time) {
        $('#booking-modal').fadeIn();
        $('#booking-modal-overlay').fadeIn();
        $('#booking-form').data('time', time);
    }

    $('#close-modal, #booking-modal-overlay').on('click', function () {
        $('#booking-modal').fadeOut();
        $('#booking-modal-overlay').fadeOut();
    });

    // Handle form submission
    // $('#booking-form').on('submit', function (e) {
    //     e.preventDefault();
    //     alert(`Appointment confirmed at ${$(this).data('time')}!`);
    //     $('#booking-modal, #booking-modal-overlay').fadeOut();
    // });

    // Handle form submission with Razorpay integration
    $('#booking-form').on('submit', function(e) {
        e.preventDefault();
        showLoadingState();
        
        const formData = {
            action: 'handle_booking',
            user_type: $('#user_type').val(),
            name: $('#name').val(),
            email: $('#email').val(),
            phone: $('#phone').val(),
            date: flatpickr.formatDate(calendar.selectedDates[0], 'Y-m-d'),
            time: $(this).data('time'),
            product_id: $('#product').val(),
            amount: $('#product option:selected').data('price')
        };
    
        $.ajax({
            url: clinicData.ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                hideLoadingState();
                if (response.success) {
                    initializeRazorpayPayment(response.data);
                } else {
                    showErrorMessage('Error creating appointment: ' + 
                        (response.data.error || 'Please try again.'));
                }
            },
            error: function() {
                hideLoadingState();
                showErrorMessage('Network error. Please try again.');
            }
        });
    });

    function initializeRazorpayPayment(data) {
        const options = {
            key: clinicData.razorpay_key,
            amount: $('#product option:selected').data('price') * 100, // Amount in paise
            currency: 'INR',
            name: 'Clinic Appointment',
            description: 'Appointment Booking',
            order_id: data.order_id,
            handler: function (response) {
                // Show loading indicator
                showLoadingState();
                
                // Verify payment
                $.ajax({
                    url: clinicData.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'verify_payment',
                        razorpay_payment_id: response.razorpay_payment_id,
                        appointment_id: data.appointment_id
                    },
                    success: function(verificationResponse) {
                        hideLoadingState();
                        if (verificationResponse.success) {
                            showSuccessMessage('Appointment booked successfully!');
                            $('#booking-modal, #booking-modal-overlay').fadeOut();
                            // Optionally refresh the calendar or redirect
                            setTimeout(() => {
                                location.reload();
                            }, 2000);
                        } else {
                            showErrorMessage('Payment verification failed: ' + 
                                (verificationResponse.data.error || 'Please contact support.'));
                        }
                    },
                    error: function() {
                        hideLoadingState();
                        showErrorMessage('Network error during payment verification. Please contact support.');
                    }
                });
            },
            prefill: {
                name: $('#name').val(),
                email: $('#email').val(),
                contact: $('#phone').val()
            },
            modal: {
                ondismiss: function() {
                    showErrorMessage('Payment cancelled. Please try again.');
                }
            },
            theme: {
                color: '#28a745'
            }
        };
    
        const rzp = new Razorpay(options);
        rzp.open();
    }
    
    // Helper functions for UI feedback
    function showLoadingState() {
        // Add loading overlay or spinner
        $('body').append('<div id="loading-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999;"><div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white;">Processing payment...</div></div>');
    }
    
    function hideLoadingState() {
        $('#loading-overlay').remove();
    }
    
    function showSuccessMessage(message) {
        alert(message); // Replace with better UI feedback
    }
    
    function showErrorMessage(message) {
        alert(message); // Replace with better UI feedback
    }

    

});