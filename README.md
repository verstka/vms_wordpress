# ![Verstka Logo](https://verstka.io/favicon.ico) Verstka Backend WordPress Plugin ![Verstka Logo](https://verstka.io/favicon.ico)  
**Powerful design tool & WYSIWYG API integration for WordPress**

[![License](https://img.shields.io/github/license/verstka/vms_wordpress)](LICENSE)
[![WordPress Version](https://img.shields.io/wordpress/plugin/v/verstka-backend)](https://wordpress.org/plugins/verstka-backend/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://php.net/)

## 🌟 Features

- **Dual-Mode Visual Editor** - Create and edit content in both desktop and mobile layouts
- **Seamless WordPress Integration** - Works with standard posts and pages
- **Responsive Content Delivery** - Automatic device-appropriate content switching
- **Developer Friendly** - REST API support, hooks, and debugging tools
- **Secure Authentication** - Signed callbacks and API key protection

## 📦 Installation

1. Download the [latest release](https://github.com/verstka/vms_wordpress/releases)
2. Upload the plugin ZIP file via WordPress Admin → Plugins → Add New
3. Activate the plugin
4. Configure your API settings under Settings → Verstka Backend

## ⚙️ Configuration

After activation:
1. Navigate to **Settings → Verstka Backend**
2. Set up your:
   - API Key (provided by Verstka)
   - Secret Key
   - Image storage directory
   - Desktop/Mobile default widths
3. Save settings

## 🖥️ Usage

### Read the [documentation](https://verstka.super.site)

### Editing Content
1. Edit any post/page in WordPress
2. Click "Edit in Verstka" (available in both Classic and Block editors and also in posts lists)
3. Choose desktop or mobile mode
4. Design your content in Verstka's visual editor
5. Save changes back to WordPress automatically

### Frontend Display
- Verstka articles automatically:
  - Load the appropriate layout for the visitor's device
  - Include the Verstka API script
  - Apply responsive viewport settings

## 🛠 Developer Notes

### Custom CSS file with additional site fonts
Verstka supports free google fonts by default, but also you can add custom fonts by making fonts.css:

You need to collect a CSS file with certain comments and fonts sewn into base64, and then they will automatically appear
in the Layout.
default url /vms_fonts.css

At the top of the CSS file you need to specify the default font in the comments, which will be set when creating a new
text object.

```css
/* default_font_family: 'formular'; */
/* default_font_weight: 400; */
/* default_font_size: 16px; */
/* default_line_height: 24px; */
```

Further, for each `@ font-face` it is necessary to register comments with the name of the font and its style.

```css
/* font_name: 'Formular'; */
/* font_style_name: 'Light'; */
```

Final CSS file:

```css
/* default_font_family: 'formular'; */
/* default_font_weight: 400; */
/* default_font_size: 16px; */
/* default_line_height: 24px; */

@ font-face {
    /* font_name: 'Formular'; */
    /* font_style_name: 'Light'; */
    font-family: 'formular';
    src: url (data: application / font-woff2;
    charset = utf-8;
    base64, KJHGKJHG . . .) format ('woff2'), url (data: application / font-woff;
    charset = utf-8;
    base64, KJHGKJHGJ . . .) format ('woff');
    font-weight: 300;
    font-style: normal;
}

@ font-face {
    /* font_name: 'Formular'; */
    /* font_style_name: 'Regular; */
    font-family: 'formular';
    src: url (data: application / font-woff2;
    charset = utf-8;
    base64, AAFEWDDWEDD . . .) format ('woff2'), url (data: application / font-woff;
    charset = utf-8;
    base64, AAFEWDDWEDD . . .) format ('woff');
    font-weight: 400;
    font-style: normal;
}
```
