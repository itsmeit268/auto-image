<?php

/*
Plugin Name: Auto Save Image
Plugin URI: https://github.com/itsmeit268/auto-image
Description: Automatically save external images to the media library and update the image URLs.
Version: 1.0.0
Author: itsmeit.co
Author URI: https://itsmeit.co/
Network: true
Text Domain: auto-image
Copyright 2024 itsmeit.co (email: buivanloi.2010@gmail.com)
*/

defined('ABSPATH') or die();

class Auto_SaveImages
{
    public function __construct()
    {
        add_action('save_post', array($this, 'save_post_images'));
    }

    public function getImages($imgURL)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $imgURL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $rescode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            echo 'Lỗi curl: ' . curl_error($ch);
        }

        curl_close($ch);

        if ($rescode == 200) {
            return $response;
        }

        return null;
    }

    public function saveImage($imgURL, $post_id){
        $response = $this->getImages($imgURL);
        $image_name = basename($imgURL);
        $filetype   = wp_check_filetype($image_name);

        $unique_file_name =  uniqid() . "." . $filetype['ext'] ? : "jpg";
//        $unique_file_name = $image_name . "-" . substr(uniqid(), 0, 3) . "." . $filetype['ext'] ? : "jpg";
//        $unique_file_name = $image_name;

        $upload_dir = wp_upload_dir();
        $filename = $upload_dir['path'] . '/' . $unique_file_name;
        $baseurl = $upload_dir['baseurl'] . $upload_dir['subdir'] . '/' . $unique_file_name;

        $filesize = strlen($response);

        if ($filesize > 100) {
            file_put_contents($filename, $response);
            
            $wp_filetype = wp_check_filetype(basename($filename), null);
            $image_title = pathinfo($unique_file_name, PATHINFO_FILENAME);

            $attachment = array(
                'post_mime_type' => $wp_filetype['type'],
                'post_title' => sanitize_file_name($image_title),
                'post_content' => '',
                'post_status' => 'inherit',
                'post_parent'  => $post_id,
            );

            $attach_id = wp_insert_attachment($attachment, $filename);
            $imagenew = get_post($attach_id);
            $fullsizepath = get_attached_file($imagenew->ID);

            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $fullsizepath);
            wp_update_attachment_metadata($attach_id, $attach_data);
            $attachment_post_id = $attach_id;

            update_post_meta($post_id, '_thumbnail_id', $attachment_post_id);
            $output['url'] = $imgURL;
            $output['file_name'] = $unique_file_name;
            $output['path'] = $filename;
            $output['baseurl'] = $baseurl;
            $output['attach_id'] = $attach_id;
            $output['url_out'] = $baseurl;
        }
        return $output ? : '';
    }

    public function save_post_images($post_id)
    {
        // Kiểm tra nếu đây là một bản lưu tự động thì dừng lại
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Lấy nội dung bài viết
        $post_content = get_post_field('post_content', $post_id);
        $post_title = get_post_field('post_title', $post_id);

        // Tìm tất cả các URL ảnh trong nội dung bài viết
        preg_match_all('/<img[^>]+src=["\'](.*?)["\']/', $post_content, $matches);

        // Nếu tìm thấy URL ảnh
        if (!empty($matches[1])) {
            foreach ($matches[1] as $imgURL) {
                // Phân tích URL của hình ảnh
                $parsed_url = wp_parse_url($imgURL);
                $current_domain = wp_parse_url(home_url(), PHP_URL_HOST);

                // Nếu domain của URL ảnh khác domain của trang hiện tại
                if (!empty($parsed_url['host']) && $parsed_url['host'] !== $current_domain) {
                    // Lưu hình ảnh vào thư viện Media
//                    file_put_contents(plugin_dir_path(__FILE__) . 'imgURL.log', $imgURL);

                    $image_data = $this->saveImage($imgURL, $post_id);

                    if ($image_data && !empty($image_data['url_out'])) {
                        // Thay thế URL cũ bằng URL mới trong nội dung bài viết
                        $post_content = str_replace($imgURL, $image_data['url_out'], $post_content);
                    }
                }
            }

            // Cập nhật lại nội dung bài viết với URL hình ảnh mới
            remove_action('save_post', array($this, 'save_post_images'));
            wp_update_post(array('ID' => $post_id, 'post_content' => $post_content));
            add_action('save_post', array($this, 'save_post_images'));
        }

    }
}

new Auto_SaveImages();