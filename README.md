# Drop X T-Shirt Designer

A WordPress plugin that adds a custom T-shirt builder to Elementor pages and sends each customer design through WooCommerce checkout.

## What is New in **v2**

1. Moved print-side controls under the shirt preview and added zoom buttons (in dropx.php).
2. Sizes now dynamically scale the shirt using a size→scale map; selected size button highlights (in assets/designer.js).
3. Added a subtle grid overlay in the safe area and styled zoom controls; smoother shirt scaling and prevented horizontal scroll (in assets/designer.css).
4. Size buttons highlight on hover and show an active color when selected 
5. Front/Back buttons highlight on hover and show active color when selected
6. I updated the designer so when the shirt is zoomed in, you can also drag the shirt itself.

## What it does

- V2.0
- Customer controls for print side, garment colour, size, text, font, text colour, and artwork upload.
- Draggable, resizable live artwork/text preview for front and back prints.
- Elementor widget: **Drop X T-Shirt Designer**.
- Shortcode alternative: `[dropx_tshirt_designer product_id="123"]`.
- A customer design is kept as a separate cart line item, so differently customized T-shirts never merge.
- Cart, checkout, order, and admin order metadata retain colour, size, text, and links to uploaded artwork.

## Install and configure

1. Copy this folder to `wp-content/plugins/dropx-tshirt-designer/` and activate **Drop X T-Shirt Designer**.
2. Activate WooCommerce and Elementor.
3. Create a simple WooCommerce product for the custom T-shirt, set its price, and publish it.
4. In WordPress go to **WooCommerce → Drop X Designer**, then choose that product as the default.
5. In Elementor, drag **Drop X T-Shirt Designer** onto a page. Optionally enter a product ID and custom title in its settings.

The selected size and colour are saved as production instructions alongside the order. For stock-managed colour/size variation pricing, create a matching simple product per selling configuration or extend the plugin to map these controls to your WooCommerce variations.

## Print fulfilment

Uploaded customer artwork is saved in the WordPress uploads folder only after the customer adds the design to the cart. Open the artwork link in the WooCommerce order details to download the file for production.

##  Responsive layout added

- The designer now adapts to both screen width and screen height, so it should fit better on phones, tablets, and shorter viewports.

1. The main layout now uses fluid sizing instead of fixed widths
2. The preview area scales with viewport height and width
3. The controls and spacing adjust for smaller screens and shorter screens
4. Mobile-specific rules were tightened so the UI stacks cleanly

## Google Fonts 

- There are currently 74 font options listed in the picker, plus the 4 built-in style options in the dropdown itself.
1. 74 Google Font choices
2. 4 built-in preset styles: Modern Sans, Classic Serif, Bold Display, and Mono

## Add the Zoom button &  unncerry scroll

- remove the existing print-side control from the controls column and insert preview controls (front/back buttons + zoom) under the preview area.

## Drag shirt while zoomed

- Added state.pan to keep track of the shirt pan offset
- Updated shirt transform to use translate(x, y) scale(...)
- Added pan drag handling when the user drags the shirt background at zoom ≠ 1
- Kept existing text/artwork drag behavior intact