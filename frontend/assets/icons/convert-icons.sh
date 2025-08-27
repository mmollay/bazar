#!/bin/bash

# Icon conversion script for Bazar marketplace
# Requires: ImageMagick or librsvg-bin (rsvg-convert)

ICON_DIR="/Applications/XAMPP/xamppfiles/htdocs/bazar/frontend/assets/icons"
cd "$ICON_DIR"

echo "Converting SVG icons to PNG format..."

# Check if ImageMagick is available
if command -v magick &> /dev/null || command -v convert &> /dev/null; then
    echo "Using ImageMagick for conversion..."
    
    # Convert favicon (32x32)
    if [ -f "favicon.svg" ]; then
        magick favicon.svg -background none -size 32x32 favicon-32x32.png
        echo "✓ favicon-32x32.png created"
    else
        convert favicon.svg -background none -size 32x32 favicon-32x32.png
        echo "✓ favicon-32x32.png created"
    fi
    
    # Convert apple touch icon (180x180)
    if [ -f "apple-touch-icon.svg" ]; then
        magick apple-touch-icon.svg -background none -size 180x180 apple-touch-icon.png
        echo "✓ apple-touch-icon.png created"
    else
        convert apple-touch-icon.svg -background none -size 180x180 apple-touch-icon.png
        echo "✓ apple-touch-icon.png created"
    fi

# Check if rsvg-convert is available
elif command -v rsvg-convert &> /dev/null; then
    echo "Using rsvg-convert for conversion..."
    
    # Convert favicon (32x32)
    if [ -f "favicon.svg" ]; then
        rsvg-convert -w 32 -h 32 favicon.svg > favicon-32x32.png
        echo "✓ favicon-32x32.png created"
    fi
    
    # Convert apple touch icon (180x180)
    if [ -f "apple-touch-icon.svg" ]; then
        rsvg-convert -w 180 -h 180 apple-touch-icon.svg > apple-touch-icon.png
        echo "✓ apple-touch-icon.png created"
    fi

else
    echo "❌ No SVG conversion tool found!"
    echo "Please install either:"
    echo "  - ImageMagick: brew install imagemagick (macOS) or apt-get install imagemagick (Linux)"
    echo "  - librsvg: brew install librsvg (macOS) or apt-get install librsvg2-bin (Linux)"
    echo ""
    echo "Alternative: Use online SVG to PNG converter with the provided SVG files"
    exit 1
fi

echo ""
echo "Icon conversion complete! 🎉"
echo ""
echo "Files created:"
echo "  - favicon-32x32.png (32x32 pixels)"
echo "  - apple-touch-icon.png (180x180 pixels)"
echo ""
echo "Note: You can also create additional sizes if needed:"
echo "  - favicon-16x16.png (16x16 pixels)"
echo "  - favicon-48x48.png (48x48 pixels)"
echo "  - apple-touch-icon-152x152.png (for older iOS devices)"