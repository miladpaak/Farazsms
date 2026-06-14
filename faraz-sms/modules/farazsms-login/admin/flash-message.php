<?php

namespace FarazSMS\Admin;

class FlashMessage {

    const SUCCESS = 1;
    const ERROR = 2;

    public static function add($message = '', $type = self::SUCCESS) {
        // Support both named parameter and regular parameter
        if (is_string($message) && !empty($message)) {
            // Use WordPress transients instead of sessions for better compatibility
            set_transient('farazsms_flash_message', [
                'message' => $message,
                'type' => $type
            ], 30); // 30 seconds
        } elseif (is_array($message) && isset($message['message'])) {
            // Backward compatibility for array format
            set_transient('farazsms_flash_message', [
                'message' => $message['message'],
                'type' => isset($message['type']) ? $message['type'] : $type
            ], 30);
        }
    }

    public static function show() {
        $message = get_transient('farazsms_flash_message');
        if($message) {
            if($message['type'] == self::SUCCESS) {
                echo "<div class='alert alert-success'>" . $message['message'] . "</div>";
            } else {
                echo "<div class='alert alert-danger'>" . $message['message'] . "</div>";
            }

            self::clear();
        }
    }

    public static function get() {

        $message_text = '';

        $message = get_transient('farazsms_flash_message');
        if($message) {
            if($message['type'] == self::SUCCESS) {
                $message_text = "<div class='alert alert-success flex align-items-center'>"
                . "<svg width='60' height='60' viewBox='0 0 60 60' fill='none' xmlns='http://www.w3.org/2000/svg'>"
                    . "<path fill-rule='evenodd' clip-rule='evenodd' d='M55 27.0548C55 28.0973 54.1525 28.9448 53.11 28.9448H53.0875V28.8998C52.0325 28.8998 51.1775 28.0473 51.175 26.9923V26.9873V20.6323C51.175 12.9998 47 8.82476 39.39 8.82476H20.64C13.025 8.82476 8.825 13.0248 8.825 20.6323V39.3823C8.825 46.9673 13.025 51.1673 20.6325 51.1673H39.3825C46.99 51.1673 51.1675 46.9673 51.1675 39.3823C51.1675 38.3273 52.0225 37.4698 53.08 37.4698C54.1375 37.4698 54.9925 38.3273 54.9925 39.3823C55 49.0198 49.02 54.9998 39.39 54.9998H20.6325C10.98 54.9998 5 49.0198 5 39.3898V20.6398C5 10.9798 10.98 4.99976 20.6325 4.99976H39.3825C48.975 4.99976 55 10.9798 55 20.6323V27.0548ZM27.034 33.2835L37.574 22.741C38.3065 22.0085 39.494 22.0085 40.2265 22.741C40.959 23.4735 40.959 24.661 40.2265 25.3935L28.359 37.261C28.0065 37.611 27.529 37.8085 27.034 37.8085C26.534 37.8085 26.059 37.611 25.7065 37.261L19.774 31.326C19.0415 30.5935 19.0415 29.406 19.774 28.6735C20.5065 27.941 21.694 27.941 22.4265 28.6735L27.034 33.2835Z'/>"
                . "</svg>"
                . $message['message'] . "</div>";
            } else {
                $message_text = "<div class='alert alert-danger flex align-items-center'>"
                . "<svg width='44' height='44' viewBox='0 0 44 44' fill='none' xmlns='http://www.w3.org/2000/svg'>"
                    . "<path fill-rule='evenodd' clip-rule='evenodd' d='M41.6953 20.7523C42.5988 20.7523 43.3333 20.0178 43.3333 19.1143V13.5482C43.3333 5.18267 38.1117 0 29.7982 0H13.5482C5.18267 0 0 5.18267 0 13.5547V29.8047C0 38.1507 5.18267 43.3333 13.5482 43.3333H29.8047C38.1507 43.3333 43.3333 38.1507 43.3268 29.7982C43.3268 28.8838 42.5837 28.1407 41.6693 28.1407C40.7528 28.1407 40.0118 28.8838 40.0118 29.7982C40.0118 36.3718 36.3913 40.0118 29.7982 40.0118H13.5482C6.955 40.0118 3.315 36.3718 3.315 29.7982V13.5482C3.315 6.955 6.955 3.315 13.5547 3.315H29.8047C36.4 3.315 40.0183 6.93333 40.0183 13.5482V19.0558V19.0602C40.0205 19.9745 40.7615 20.7133 41.6758 20.7133V20.7523H41.6953ZM16.9321 24.1484L15.4501 25.6304C14.7892 26.2587 14.7524 27.3009 15.3677 27.9747L15.4154 28.0094C16.0481 28.642 17.0664 28.6594 17.7186 28.0484L19.1941 26.5729C19.8636 25.9489 19.9004 24.9002 19.2764 24.2285C18.6502 23.5612 17.6016 23.5222 16.9321 24.1484ZM28.4756 27.7405C27.8386 28.3753 26.8095 28.3883 26.1573 27.7665L26.075 27.6863L15.7183 17.3318C15.0856 16.6602 15.0726 15.6158 15.6901 14.9312C16.3228 14.2833 17.3585 14.2703 18.0063 14.903C18.0128 14.9073 18.0171 14.9116 18.0236 14.9181L22.0645 18.9611L25.7413 15.2822C26.4021 14.6517 27.4443 14.6581 28.0986 15.2973C28.2156 15.4143 28.3153 15.5465 28.3911 15.6938C28.766 16.346 28.6576 17.165 28.1268 17.698L24.4651 21.3575L28.4475 25.342C29.1191 25.9768 29.1451 27.0363 28.5081 27.7058C28.5032 27.7132 28.4969 27.7192 28.4903 27.7254C28.4854 27.7301 28.4803 27.7349 28.4756 27.7405Z' fill='#F15271'/>"
                . "</svg>"
                . $message['message'] . "</div>";
            }

            self::clear();
        }

        return $message_text;
    }

    public static function clear() {
        delete_transient('farazsms_flash_message');
    }
}