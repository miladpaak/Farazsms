(function($) {
    'use strict';

    $(document).ready(function() {
        var $slide = $('.farazsms-exit-slide');
        
        if ($slide.length === 0) {
            return;
        }

        var countdownSeconds = parseInt($slide.data('countdown')) || 120;
        var currentSeconds = countdownSeconds;
        var countdownInterval = null;
        var slideShown = false;
        var circleProgress = $slide.find('.farazsms-countdown-circle-progress');
        var $countdownSeconds = $slide.find('.farazsms-countdown-seconds');
        var circumference = 2 * Math.PI * 45; // radius is 45
        
        // Set initial stroke-dasharray
        circleProgress.attr('stroke-dasharray', circumference);
        circleProgress.attr('stroke-dashoffset', circumference);

        // Show slide after 1 second (show on every page load)
        setTimeout(function() {
            showSlide();
        }, 1000);

        function showSlide() {
            if (slideShown) {
                return;
            }
            
            slideShown = true;
            
            // Add show class for animation
            $slide.addClass('show');

            // Start countdown
            startCountdown();
        }

        function startCountdown() {
            updateCountdown();
            countdownInterval = setInterval(function() {
                currentSeconds--;
                updateCountdown();
                
                if (currentSeconds <= 0) {
                    clearInterval(countdownInterval);
                    hideSlide();
                }
            }, 1000);
        }

        function updateCountdown() {
            $countdownSeconds.text(currentSeconds);
            
            // Update circle progress
            var progress = (countdownSeconds - currentSeconds) / countdownSeconds;
            var offset = circumference - (progress * circumference);
            circleProgress.attr('stroke-dashoffset', offset);
        }

        function hideSlide() {
            $slide.removeClass('show');
        }

        // Close button handler
        $slide.find('.farazsms-slide-close').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            hideSlide();
        });
    });

})(jQuery);

