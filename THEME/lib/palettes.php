<?php
/**
 * The colour palettes of the theme.
 *
 * Each palette gives new values to the colour variables that the core declares
 * in Common/Styles/colors.css and that Blue_screen.css reads: nothing else of
 * the core has to change for a whole page to take the palette.
 *
 * HOW THE VALUES WERE MADE. The light version of every palette is ianseo's own
 * blue turned to another hue, each role keeping its perceived lightness (OKLCH
 * colour space): a green title bar is exactly as dark as the blue one, so text
 * stays as readable as in the core. The dark versions follow one fixed scale of
 * lightness for every hue. Every text and background pair has a contrast ratio
 * of 6.5:1 or more (4.5:1 is the WCAG minimum); the core's blue sits at 9:1.
 *
 * Two variables exist only in the dark versions, because the core uses
 * --header-dark both as the background of title bars and as the text colour of
 * ordinary rows, which no single dark value can serve:
 *   --thm-text  text of rows and column headers;
 *   --thm-link  links.
 *
 * 'ianseo' carries the core's own light values for the previews only: they are
 * never sent, colors.css already has them. 'debug' is not offered as a choice:
 * it replaces the chosen palette while the core's debug mode is on, and its
 * light values are those of the core's colors_debug.css, which the core loads
 * itself.
 */

/**
 * Every palette, the ones offered first and in the order they are offered.
 *
 * @return array Palette id => ['light' => [variable => colour], 'dark' => [...]].
 */
function thm_palettes() {
    return [
        'ianseo' => [
            'light' => ['header-light' => '#F0F8FF', 'table-background' => '#E1F0FF', 'row-background' => '#F9FCFF', 'row-main-text' => '#F2F9FF', 'bg-cell-header' => '#BFDDFF', 'input-border' => '#97B9E6', 'main-text' => '#000099', 'header-dark' => '#004488', 'border-dark' => '#0066CC'],
            'dark' => ['header-light' => '#11151B', 'row-background' => '#1A2027', 'table-background' => '#2A323D', 'bg-cell-header' => '#25364C', 'header-dark' => '#2B4E7A', 'row-main-text' => '#ECF2FA', 'main-text' => '#BAD7FC', 'border-dark' => '#79A7E2', 'input-border' => '#4A5C74', 'thm-text' => '#D9E4F2', 'thm-link' => '#9CC7FF'],
        ],
        'green' => [
            'light' => ['header-light' => '#F2F9F3', 'table-background' => '#E4F3E7', 'row-background' => '#FAFCFA', 'row-main-text' => '#F4FAF5', 'bg-cell-header' => '#C3E3CA', 'input-border' => '#99C3A3', 'main-text' => '#023B1A', 'header-dark' => '#005528', 'border-dark' => '#037F3F'],
            'dark' => ['header-light' => '#111712', 'row-background' => '#1B211C', 'table-background' => '#2B342D', 'bg-cell-header' => '#263B2C', 'header-dark' => '#2B5839', 'row-main-text' => '#EDF4EE', 'main-text' => '#BCDEC4', 'border-dark' => '#7CB48A', 'input-border' => '#4B6150', 'thm-text' => '#DAE7DD', 'thm-link' => '#9DD4AB'],
        ],
        'teal' => [
            'light' => ['header-light' => '#EFF9FA', 'table-background' => '#DEF3F4', 'row-background' => '#F8FDFD', 'row-main-text' => '#F1FAFA', 'bg-cell-header' => '#B5E4E6', 'input-border' => '#85C3C6', 'main-text' => '#01383A', 'header-dark' => '#045053', 'border-dark' => '#01787E'],
            'dark' => ['header-light' => '#0F1717', 'row-background' => '#182122', 'table-background' => '#273535', 'bg-cell-header' => '#1B3B3D', 'header-dark' => '#04585C', 'row-main-text' => '#EAF4F4', 'main-text' => '#AEDFE1', 'border-dark' => '#5DB5BA', 'input-border' => '#416263', 'thm-text' => '#D5E7E8', 'thm-link' => '#82D4D9'],
        ],
        'olive' => [
            'light' => ['header-light' => '#F6F8F0', 'table-background' => '#ECF0E1', 'row-background' => '#FBFCF9', 'row-main-text' => '#F7F9F2', 'bg-cell-header' => '#D6DEBD', 'input-border' => '#B3BC91', 'main-text' => '#2D3401', 'header-dark' => '#424C01', 'border-dark' => '#647205'],
            'dark' => ['header-light' => '#141610', 'row-background' => '#1F2019', 'table-background' => '#313329', 'bg-cell-header' => '#343823', 'header-dark' => '#4A5224', 'row-main-text' => '#F1F3EC', 'main-text' => '#D1D9B6', 'border-dark' => '#A0AC72', 'input-border' => '#595E47', 'thm-text' => '#E2E5D8', 'thm-link' => '#BFCB93'],
        ],
        'violet' => [
            'light' => ['header-light' => '#F9F5FE', 'table-background' => '#F2EAFC', 'row-background' => '#FCFBFE', 'row-main-text' => '#F9F6FE', 'bg-cell-header' => '#E2D1F7', 'input-border' => '#C1ABDB', 'main-text' => '#44016E', 'header-dark' => '#553079', 'border-dark' => '#8149B5'],
            'dark' => ['header-light' => '#16131A', 'row-background' => '#211E25', 'table-background' => '#342F3A', 'bg-cell-header' => '#3A3047', 'header-dark' => '#574170', 'row-main-text' => '#F3F0F8', 'main-text' => '#DDCBF3', 'border-dark' => '#B295D5', 'input-border' => '#60556E', 'thm-text' => '#E6E0EE', 'thm-link' => '#D2B6F4'],
        ],
        'raspberry' => [
            'light' => ['header-light' => '#FEF4F7', 'table-background' => '#FCE8EF', 'row-background' => '#FFFAFC', 'row-main-text' => '#FEF5F8', 'bg-cell-header' => '#F8CCDB', 'input-border' => '#DCA4B9', 'main-text' => '#5B0133', 'header-dark' => '#742149', 'border-dark' => '#AE336E'],
            'dark' => ['header-light' => '#1A1215', 'row-background' => '#251C20', 'table-background' => '#3B2D32', 'bg-cell-header' => '#472C36', 'header-dark' => '#6F394F', 'row-main-text' => '#F9EFF2', 'main-text' => '#F4C5D6', 'border-dark' => '#D48BA8', 'input-border' => '#6F515C', 'thm-text' => '#EFDEE3', 'thm-link' => '#F4ACC7'],
        ],
        'burgundy' => [
            'light' => ['header-light' => '#FFF4F3', 'table-background' => '#FDE8E7', 'row-background' => '#FFFAFA', 'row-main-text' => '#FFF6F5', 'bg-cell-header' => '#FACDCB', 'input-border' => '#DEA6A3', 'main-text' => '#600010', 'header-dark' => '#782528', 'border-dark' => '#B3373D'],
            'dark' => ['header-light' => '#1A1312', 'row-background' => '#261D1C', 'table-background' => '#3B2E2D', 'bg-cell-header' => '#482D2C', 'header-dark' => '#713B3A', 'row-main-text' => '#F9EFEE', 'main-text' => '#F6C7C4', 'border-dark' => '#D78E8B', 'input-border' => '#705251', 'thm-text' => '#F0DEDD', 'thm-link' => '#F7AEAB'],
        ],
        'slate' => [
            'light' => ['header-light' => '#F6F7F8', 'table-background' => '#ECEEF1', 'row-background' => '#FBFCFC', 'row-main-text' => '#F7F8F9', 'bg-cell-header' => '#D5DAE0', 'input-border' => '#B1B7BF', 'main-text' => '#233143', 'header-dark' => '#3D4652', 'border-dark' => '#5D6B7C'],
            'dark' => ['header-light' => '#141516', 'row-background' => '#1E1F21', 'table-background' => '#303234', 'bg-cell-header' => '#33363A', 'header-dark' => '#484E56', 'row-main-text' => '#F1F2F3', 'main-text' => '#D0D5DB', 'border-dark' => '#9DA5B0', 'input-border' => '#585B5F', 'thm-text' => '#E1E3E5', 'thm-link' => '#BDC5CF'],
        ],
        'debug' => [
            'light' => ['header-light' => '#FFF8F0', 'table-background' => '#FFF0E1', 'row-background' => '#FFFCF9', 'row-main-text' => '#FFF9F2', 'bg-cell-header' => '#FFDDBF', 'input-border' => '#97B9E6', 'main-text' => '#660000', 'header-dark' => '#884400', 'border-dark' => '#CC6600'],
            'dark' => ['header-light' => '#1A1310', 'row-background' => '#251E19', 'table-background' => '#3A2F28', 'bg-cell-header' => '#463021', 'header-dark' => '#6D4121', 'row-main-text' => '#F8F0EB', 'main-text' => '#F1CCB3', 'border-dark' => '#D1966D', 'input-border' => '#6D5545', 'thm-text' => '#EEE0D7', 'thm-link' => '#F0B68F'],
        ],
    ];
}

/**
 * Ids of the palettes a user may choose.
 *
 * @return string[]
 */
function thm_palette_ids() {
    return array_values(array_diff(array_keys(thm_palettes()), ['debug']));
}
