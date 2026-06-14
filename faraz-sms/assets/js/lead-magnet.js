/**
 * Lead Magnet Box JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        var $box = $('.farazsms-lead-magnet-box');
        
        if (!$box.length) {
            return;
        }

        var countdownSeconds = farazsmsLeadMagnet.countdownSeconds || 60;
        var currentSeconds = countdownSeconds;
        var $countdownMinutes = $box.find('.countdown-minutes');
        var $countdownSeconds = $box.find('.countdown-seconds');
        var $progressCircle = $box.find('.countdown-circle-progress');
        var countdownInterval = null;
        var isClosed = false;
        var totalSeconds = countdownSeconds;
        var circumference = 2 * Math.PI * 45; // radius = 45
        var initialOffset = circumference;

        // Set initial stroke-dashoffset
        $progressCircle.css('stroke-dashoffset', initialOffset);

        /**
         * Format time as MM:SS
         */
        function formatTime(seconds) {
            var mins = Math.floor(seconds / 60);
            var secs = seconds % 60;
            return {
                minutes: mins.toString().padStart(2, '0'),
                seconds: secs.toString().padStart(2, '0')
            };
        }

        /**
         * Update countdown display
         */
        function updateCountdown() {
            if (currentSeconds <= 0) {
                closeBox();
                return;
            }

            var time = formatTime(currentSeconds);
            $countdownMinutes.text(time.minutes);
            $countdownSeconds.text(time.seconds);

            // Update progress circle
            var progress = (totalSeconds - currentSeconds) / totalSeconds;
            var offset = initialOffset - (progress * circumference);
            $progressCircle.css('stroke-dashoffset', offset);

            currentSeconds--;
        }

        /**
         * Start countdown timer
         */
        function startCountdown() {
            if (countdownInterval) {
                clearInterval(countdownInterval);
            }

            // Update immediately
            updateCountdown();

            // Update every second
            countdownInterval = setInterval(function() {
                updateCountdown();
            }, 1000);
        }

        /**
         * Close the box
         */
        function closeBox() {
            if (isClosed) {
                return;
            }

            isClosed = true;

            // Clear countdown
            if (countdownInterval) {
                clearInterval(countdownInterval);
                countdownInterval = null;
            }

            // Add closing class for animation
            $box.addClass('closing');

            // Remove box after animation
            setTimeout(function() {
                $box.remove();
            }, 300);
        }

        /**
         * Handle close button click
         */
        $box.find('.farazsms-lead-magnet-close').on('click', function(e) {
            e.preventDefault();
            closeBox();
        });

        /**
         * Handle CTA button click - redirect to selected page (or login/register fallback)
         */
        $box.find('.lead-magnet-cta-button').on('click', function(e) {
            e.preventDefault();
            var redirectUrl = (farazsmsLeadMagnet && farazsmsLeadMagnet.redirectUrl)
                ? farazsmsLeadMagnet.redirectUrl
                : window.location.origin + '/wp-login.php';
            window.location.href = redirectUrl;
        });

        /**
         * Start countdown when box is visible
         */
        if ($box.is(':visible')) {
            startCountdown();
        }

        // Handle page visibility change (pause/resume countdown)
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // Page is hidden, pause countdown
                if (countdownInterval) {
                    clearInterval(countdownInterval);
                    countdownInterval = null;
                }
            } else {
                // Page is visible, resume countdown if not closed
                if (!isClosed && $box.is(':visible')) {
                    startCountdown();
                }
            }
        });
    });

})(jQuery);

