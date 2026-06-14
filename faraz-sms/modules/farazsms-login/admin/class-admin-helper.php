<?php

namespace FarazSMS\Admin;

class Admin_Helper {

    public function Farazsms_Text($id, $title, $content, $width, $description = '') {
        $field = "<div id='$id' class='farazsms-field $width farazsms-field-text'>"
            . "<label for='$id'>$title</label>"
            . "<input type='text' value='". (!empty($content) ? esc_attr($content) : '') ."' name='$id' />";

        if (!empty($description)) {
            $field .= "<p class='farazsms-field-description'>$description</p>";
        }

        $field .= "</div>";

        return $field;
    }

    public function Farazsms_Number($id, $title, $content, $width, $description = '') {
        $field = "<div id='$id' class='farazsms-field $width farazsms-field-number'>"
            . "<label for='$id'>$title</label>"
            . "<input type='number' value='". (!empty($content) ? esc_attr($content) : '') ."' step='1' min='1' name='$id' />";

        if (!empty($description)) {
            $field .= "<p class='farazsms-field-description'>$description</p>";
        }

        $field .= "</div>";

        return $field;
    }

    public function Farazsms_Textarea($id, $title, $content, $width, $description = '') {
        $escaped_content = !empty($content) ? esc_attr($content) : '';
        $br_content = nl2br($escaped_content);
        $field = "<div id='$id' class='farazsms-field $width farazsms-field-textarea'>"
            . "<label for='$id'>$title</label>"
            . "<textarea name='$id' rows='4' cols='50'>". (!empty($content) ? esc_attr($content) : '') ."</textarea>";

        if (!empty($description)) {
            $field .= "<p class='farazsms-field-description'>$description</p>";
        }

        $field .= "</div>";

        return $field;
    }

    public function Farazsms_URL($id, $title, $content, $width, $description = '') {
        $field = "<div id='$id' class='farazsms-field $width farazsms-field-url'>"
            . "<label for='$id'>$title</label>"
            . "<input type='text' value='". (!empty($content) ? esc_attr($content) : '') ."' name='$id' />";

        if (!empty($description)) {
            $field .= "<p class='farazsms-field-description'>$description</p>";
        }

        $field .= "</div>";

        return $field;
    }

    public function Farazsms_File_Uploader($id, $title, $content, $width, $type, $description = '') {
        $single_id = preg_replace('/[\[\]]/', '', $id);
        $field = "<div id='$single_id' class='farazsms-field $width farazsms-field-file-uploader'>"
            . "<label for='$id'>$title</label>"
            . "<div class='flex align-items-center flex-wrap'>"
                . "<button class='button farazsms-field-upload-file' id='$single_id'>انتخاب فایل</button>"
                . "<input value='". (!empty($content) ? esc_url($content) : '') ."' type='text' class='farazsms-field-file-url' name='$id' />"
                . "<div class='farazsms-field-file-container'>";
                switch($type) {
                    case 'img' :
                        $field .= (!empty($content) ? '<img src="'. esc_url($content) .'">' : '');
                        break;
                    case 'audio' :
                        $field .= (!empty($content) ? '<audio controls=""><source src="'. esc_url($content) .'" type="audio/mpeg"></audio>' : '');
                        break;
                    case 'video' :
                        $field .= (!empty($content) ? '<video width="320" height="240" controls><source src="'. esc_url($content) .'" type="video/mp4"></video>' : '');
                        break;
                }
                $field .= "</div>"
                . "<a class='farazsms-field-delete-file". (empty($content) ? ' hidden' : '') ."' href='#'>حذف فایل</a>"
            . "</div>";

        if (!empty($description)) {
            $field .= "<p class='farazsms-field-description'>$description</p>";
        }

        $field .= "</div>";

        return $field;
    }

    public function Farazsms_Multiple_Image_Uploader($id, $title, $content, $width, $description = '') {
        $content = explode(',', $content);
        $field = "<div id='$id' class='farazsms-field $width farazsms-field-multiple-image-uploader'>"
            . "<label for='$id'>$title</label>"
            . "<div class='flex align-items-center flex-wrap'>"
                . "<button class='button farazsms-field-upload-file' id='chooseLogo'>انتخاب تصاویر</button>"
                . "<input value='". (!empty($content) ? implode(',', $content) : '') ."' type='text' class='farazsms-field-img-ids' name='$id' />"
                . "<div class='farazsms-field-file-container'>";
                if (!empty($content) && is_array($content)) {
                    foreach ($content as $image_id) {
                        $image_url = wp_get_attachment_image_url($image_id, 'thumbnail');
                        if ($image_url) {
                            $field .= "<div class='farazsms-field-img-item' data-id='$image_id'>
                                <img src='$image_url' alt=''>
                                <button class='remove-image-button' data-id='$image_id'>×</button>
                            </div>";
                        }
                    }
                }
                $field .= "</div>"
            . "</div>";

        if (!empty($description)) {
            $field .= "<p class='farazsms-field-description'>$description</p>";
        }

        $field .= "</div>";

        return $field;
    }
    

    public function Farazsms_Checkbox($id, $title, $content, $width, $description = '') {
        $field = "<div id='$id' class='farazsms-field $width farazsms-field-checkbox'>"
            . "<label for='$id'>$title</label>"
            . "<div>"
                . "<input type='checkbox' name='$id' ". ($content == true ? ' checked' : '') ." >"
                . "<span class='btn-toggle'></span>"
            . "</div>";

        if (!empty($description)) {
            $field .= "<p class='farazsms-field-description'>$description</p>";
        }

        $field .= "</div>";

        return $field;
    }

    public function Farazsms_Select($id, $title, $content, $selected, $width, $description = '') {
        $field = '';
        $field .= "<div id='$id' class='farazsms-field $width farazsms-field-select'>"
            . "<label for='$id'>$title</label>"
            . "<select class='farazsms-select select-single' name='$id'>";
                foreach($content as $key => $value) {
                    $field .= "<option ". ($selected == $key ? ' selected' : '') ." value='$key'>$value</option>";
                }
            $field .= "</select>";

        if (!empty($description)) {
            $field .= "<p class='farazsms-field-description'>$description</p>";
        }

        $field .= "</div>";

        return $field;
    }

    public function Farazsms_Select2($id, $select_id, $title, $content, $selected, $width, $description = '') {
        $field = '';
        $field .= "<div id='$id' class='farazsms-field $width farazsms-field-select2'>"
            . "<label for='$id'>$title</label>"
            . "<select class='farazsms-select select-multiple' name='$select_id' multiple='multiple'>";
                foreach($content as $key => $value) {
                    $field .= "<option ". (!empty($selected) && in_array($key, $selected) ? ' selected' : '') ." value='$key'>$value</option>";
                }
            $field .= "</select>";

        if (!empty($description)) {
            $field .= "<p class='farazsms-field-description'>$description</p>";
        }

        $field .= "</div>";

        return $field;
    }

    public function Farazsms_Color($id, $title, $value, $alpha, $default, $width, $description = '') {
        $default = !empty($default) ? $default : '';
        $alpha = $alpha == true ? " data-alpha-enabled='true'" : '';
        $field = "<div id='farazsms-field-$id' class='farazsms-field farazsms-field-color $width'>"
            . "<label for='$id'>$title</label>"
            . "<input type='text' id='$id' class='color-picker' data-alpha-color-type='hex'$alpha value='". (!empty($value) ? esc_attr($value) : $default) ."' name='$id' />";

        if (!empty($description)) {
            $field .= "<p class='farazsms-field-description'>$description</p>";
        }

        $field .= "</div>";

        return $field;
    }

    public function Farazsms_Editor($id, $title, $content, $width, $description = '') {
        $width = !empty($width) ? $width : 'w100';

        $content = html_entity_decode(stripslashes($content));

        $settings = array(
            'textarea_name'  => $id,
            'editor_class'   => $id,
            'wpautop'        => false,
            'textarea_rows'  => 15,
            'media_buttons'  => false,
            'drag_drop_upload' => false,
            'editor_height'  => 200,
            'tinymce'        => array('plugins' => 'fullscreen,wordpress,wplink,textcolor'),
        );

        ob_start();
            echo '<div class="farazsms-editor-wrapper ' . esc_attr($width) . '">';
            echo "<label for='$id'>$title</label>";
            wp_editor($content, $id, $settings);
            if (!empty($description)) {
                echo "<p class='farazsms-field-description'>$description</p>";
            }
        echo '</div>';
        return ob_get_clean();
    }
    

    public function Farazsms_Repeater($id, $title, $section, $btn, $settings, $default, $width, $description = '') {
        $field = '';
        $repeater_value = [];
        if ($section) {
            $theme_options = get_option('farazsms_main_option', []);
            $get_option = isset($theme_options[$section]) ? $theme_options[$section] : [];
            $repeater_value = isset($get_option[$id]) ? $get_option[$id] : [];
        } else {
            $repeater_value = $default;
        }
        $field .= "<div id='$id' class='farazsms-field farazsms-field-repeater'>"
            . "<label for='$id'>$title</label>"
            . "<div class='main-repeater flex flex-wrap'>";
            if (!empty($repeater_value)) {
                $i = -1;
                foreach ($repeater_value as $repeater) {
                    $i++;
                    $field .= "<div id='". $id . "[" . $i . "]' class='repeater-table $width'>"
                        . "<div class='repeater-table-entry'>"
                        . "<button class='delete-repeater-row'>حذف</button>";
                        foreach ($settings as $key => $setting) {
                            $parts = explode('[', $key);
                            $lastPart = end($parts);
                            $default_id = rtrim($lastPart, ']');
                            $key = $id . '[' . $i . '][' . $default_id . ']';
                            $setting['value'] = isset($repeater[$default_id]) ? $repeater[$default_id] : '';
                            $field .= $this->Farazsms_Type_To_Function($key, $setting);
                        }
                        $field .= "</div>"
                    . "</div>";
                }
            } else {
                $field .= "<div id='". $id . "[0]' class='repeater-table $width'>"
                    . "<div class='repeater-table-entry'>";
                    foreach ($settings as $sub_id => $setting) {
                        $setting['value'] = '';
                        $field .= $this->Farazsms_Type_To_Function($sub_id, $setting);
                    }
                    $field .= "</div>"
                . "</div>";
            }
            $field .= "<button class='button w100 button-primary add-repeater-row'>$btn</button>"
            . "</div>";

        if (!empty($description)) {
            $field .= "<p class='farazsms-field-description'>$description</p>";
        }

        $field .= "</div>";
        return $field;
    }

    public function Farazsms_Heading($id, $title) {
        $field = "<h3 id='$id' class='farazsms-field w100 farazsms-field-heading'>$title</h3>";

        return $field;
    }

    public function Farazsms_HTML($id, $title, $html) {
        $field = "<div id='farazsms-field-$id' class='farazsms-field farazsms-field-html w100'>";
            $field .= "<h4 class='farazsms-field w100'>$title</h4>";
            $field .= "<div class='farazsms-html-code'>$html</div>";
        $field .= "</div>";

        return $field;
    }

    public function Farazsms_Image_Radio($id, $title, $options, $selected, $width, $field_description = '') {
        $field = "<div id='$id' class='farazsms-field $width farazsms-field-image-radio'>"
            . "<label for='$id'>$title</label>"
            . "<div class='image-radio-container'>";

        foreach($options as $value => $option) {
            $image = isset($option['image']) ? $option['image'] : '';
            $label = isset($option['label']) ? $option['label'] : $value;
            $description = isset($option['description']) ? $option['description'] : '';

            $checked = ($selected == $value) ? ' checked' : '';
            $active_class = ($selected == $value) ? ' active' : '';

            $field .= "<div class='image-radio-item$active_class'>"
                . "<input type='radio' id='{$id}_{$value}' name='$id' value='$value'$checked />"
                . "<label for='{$id}_{$value}' class='image-radio-label'>";

            if ($image) {
                $field .= "<div class='image-radio-image'>"
                    . "<img src='" . esc_url($image) . "' alt='" . esc_attr($label) . "' />"
                    . "</div>";
            }

            $field .= "<div class='image-radio-content'>"
                . "<div class='image-radio-title'>$label</div>";

            if ($description) {
                $field .= "<div class='image-radio-description'>$description</div>";
            }

            $field .= "</div>"
                . "</label>"
                . "</div>";
        }

        $field .= "</div>";

        if (!empty($field_description)) {
            $field .= "<p class='farazsms-field-description'>$field_description</p>";
        }

        $field .= "</div>";

        return $field;
    }

    public function Farazsms_Switch($id, $title, $value, $width, $description = '') {
        $checked = ($value == '1' || $value === true) ? ' checked' : '';
        $field = "<div id='$id' class='farazsms-field $width farazsms-field-switch'>"
            . "<label for='$id' class='farazsms-switch-label'>$title</label>"
            . "<label class='farazsms-switch'>"
            . "<input type='checkbox' id='$id' name='$id' value='1'$checked />"
            . "<span class='farazsms-slider round'></span>"
            . "</label>";

        if (!empty($description)) {
            $field .= "<p class='farazsms-field-description'>$description</p>";
        }

        $field .= "</div>";

        return $field;
    }

    public function Farazsms_SMS_Test($id, $title, $phone_field_id, $width, $description = '') {
        $field = "<div id='$id' class='farazsms-field $width farazsms-field-sms-test'>"
            . "<label for='$id'>$title</label>"
            . "<div class='farazsms-sms-test-container flex align-items-center'>"
            . "<input type='text' id='{$phone_field_id}' placeholder='" . __('Enter phone number', 'farazsms') . "' class='farazsms-sms-test-phone' />"
            . "<button type='button' class='button farazsms-sms-test-send' data-phone-field='{$phone_field_id}'>" . __('Send Test SMS', 'farazsms') . "</button>"
            . "<div class='farazsms-sms-test-result'></div>"
            . "</div>";

        if (!empty($description)) {
            $field .= "<p class='farazsms-field-description'>$description</p>";
        }

        $field .= "</div>";

        return $field;
    }

    public function Farazsms_Tabs($id, $tabs, $width) {
        $field = "<div id='$id' class='$width farazsms-field-tabs'>";
        
        $field .= "<div class='farazsms-tabs-wrapper'>";
        $field .= "<div class='farazsms-tabs-nav'>";
        
        $tab_index = 0;
        foreach ($tabs as $tab_key => $tab_data) {
            $tab_title = $tab_data['title'];
            $active_class = $tab_index === 0 ? ' active' : '';
            $field .= "<button type='button' class='farazsms-tab-btn$active_class' data-tab='$tab_key'>$tab_title</button>";
            $tab_index++;
        }
        
        $field .= "</div>";
        
        $field .= "<div class='farazsms-tabs-content'>";
        $tab_index = 0;
        foreach ($tabs as $tab_key => $tab_data) {
            $tab_settings = $tab_data['settings'];
            $active_class = $tab_index === 0 ? ' active' : '';
            $field .= "<div class='farazsms-tab-pane$active_class' data-tab='$tab_key'>";
            $field .= "<div class='farazsms-form-setting flex flex-wrap'>";
            
            foreach ($tab_settings as $setting_id => $setting) {
                $field .= $this->Farazsms_Type_To_Function($setting_id, $setting);
            }
            
            $field .= "</div>";
            $field .= "</div>";
            $tab_index++;
        }
        $field .= "</div>";
        
        $field .= "</div>";
        $field .= "</div>";

        return $field;
    }

    public function Farazsms_Type_To_Function($id,$setting) {
        $type = $setting['type'];
        $content = '';
        switch($type) {
            case 'heading':
                $title = $setting['title'];
                $content = $this->Farazsms_Heading($id, $title);
                break;

            case 'textarea':
                $title = $setting['title'];
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $value = !empty($setting['value']) ? $setting['value'] : '';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_Textarea($id, $title, $value, $width, $description);
                break;

            case 'editor':
                $title = $setting['title'];
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $value = !empty($setting['value']) ? $setting['value'] : '';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_Editor($id, $title, $value, $width, $description);
                break;

            case 'text':
                $title = $setting['title'];
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $value = !empty($setting['value']) ? $setting['value'] : '';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_Text($id, $title, $value, $width, $description);
                break;

            case 'number':
                $title = $setting['title'];
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $value = !empty($setting['value']) ? $setting['value'] : '';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_Number($id, $title, $value, $width, $description);
                break;

            case 'checkbox':
                $title = $setting['title'];
                $value = !empty($setting['value']) ? $setting['value'] : 'w100';
                $width = !empty($setting['width']) ? $setting['width'] : '';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_Checkbox($id, $title, $value, $width, $description);
                break;

            case 'url':
                $title = $setting['title'];
                $value = !empty($setting['value']) ? $setting['value'] : '';
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_URL($id, $title, $value, $width, $description);
                break;
            
            case 'color':
                $title = $setting['title'];
                $value = !empty($setting['value']) ? $setting['value'] : '';
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $alpha = !empty($setting['alpha']) ? $setting['alpha'] : false;
                $default = !empty($setting['default']) ? $setting['default'] : '';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_Color($id, $title, $value, $alpha, $default, $width, $description);
                break;

            case 'select':
                $title = $setting['title'];
                $array = !empty($setting['array']) ? $setting['array'] : '';
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $selected = !empty($setting['value']) ? $setting['value'] : '';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_Select($id, $title, $array, $selected, $width, $description);
                break;

            case 'select2':
                $title = $setting['title'];
                $array = !empty($setting['array']) ? $setting['array'] : '';
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $selected = !empty($setting['value']) ? $setting['value'] : '';
                $select_id = !empty($setting['id']) ? $setting['id'] : '';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_Select2($id, $select_id, $title, $array, $selected, $width, $description);
                break;

            case 'file':
                $title = $setting['title'];
                $value = !empty($setting['value']) ? $setting['value'] : '';
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $format = !empty($setting['format']) ? $setting['format'] : 'img';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_File_Uploader($id, $title, $value, $width, $format, $description);
                break;

            case 'image-gallery':
                $title = $setting['title'];
                $ids = !empty($setting['ids']) ? $setting['ids'] : '';
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_Multiple_Image_Uploader($id, $title, $ids, $width, $description);
                break;

            case 'repeater':
                $title = $setting['title'];
                $btn = $setting['btn'];
                $repeater_settings = $setting['settings'];
                $section = isset($setting['section']) ? $setting['section'] : '';
                $default = isset($setting['value']) ? $setting['value'] : (isset($setting['default']) ? $setting['default'] : []);
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_Repeater($id, $title, $section, $btn, $repeater_settings, $default, $width, $description);
                break;

            case 'image-radio':
                $title = $setting['title'];
                $options = !empty($setting['options']) ? $setting['options'] : [];
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $selected = !empty($setting['value']) ? $setting['value'] : '';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_Image_Radio($id, $title, $options, $selected, $width, $description);
                break;

            case 'switch':
                $title = $setting['title'];
                $value = !empty($setting['value']) ? $setting['value'] : '';
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_Switch($id, $title, $value, $width, $description);
                break;

            case 'sms-test':
                $title = $setting['title'];
                $phone_field_id = !empty($setting['phone_field_id']) ? $setting['phone_field_id'] : 'test_phone';
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $description = !empty($setting['description']) ? $setting['description'] : '';
                $content = $this->Farazsms_SMS_Test($id, $title, $phone_field_id, $width, $description);
                break;
            case 'html':
                $title = $setting['title'];
                $html = !empty($setting['html']) ? $setting['html'] : '';
                $content = $this->Farazsms_HTML($id, $title, $html);
                break;

            case 'tabs':
                $tabs = !empty($setting['tabs']) ? $setting['tabs'] : [];
                $width = !empty($setting['width']) ? $setting['width'] : 'w100';
                $content = $this->Farazsms_Tabs($id, $tabs, $width);
                break;
        }

        return $content;
    }
}