<?php
/**
 * @package dompdf
 * @link    http://dompdf.github.com/
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

declare(strict_types=1);

namespace Dompdf\Adapter;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\Helpers;

/**
 * PDF rendering interface
 *
 * Dompdf\Adapter\SetaPDF provides a simple interface to the one
 * provided by SetaPDF_Core.
 *
 * Unless otherwise mentioned, all dimensions are in points (1/72 in).
 * The coordinate origin is in the top left corner and y values
 * increase downwards.
 *
 * See {@link https://www.setasign.com/products/setapdf-core/details/}
 * for more complete documentation on the underlying functions.
 *
 * Notes:
 * - pfb fonts are not supported
 * - we always use font subsetting and ignore the settings for this
 *
 * @package dompdf
 */
class SetaPDF implements Canvas
{
    /**
     * @var Dompdf
     */
    protected $dompdf;

    /**
     * @var string
     */
    protected $orientation;

    /**
     * @var mixed|string
     */
    protected $width;

    /**
     * @var mixed|string
     */
    protected $height;

    /**
     * @var int
     */
    protected $pageCount;

    /**
     * @var int
     */
    protected $pageNumber;

    /**
     * @var array
     */
    protected $pageText = [];

    /**
     * @var array
     */
    protected $imageCache = [];

    /**
     * @var \SetaPDF_Core_Document
     */
    protected $document;

    /**
     * @var null|\SetaPDF_Core_DataStructure_Color
     */
    protected $currentStrokingColor;

    /**
     * @var null|\SetaPDF_Core_DataStructure_Color
     */
    protected $currentFillColor;

    /**
     * @var null|\SetaPDF_Core_Font_FontInterface
     */
    protected $currentFont;

    protected $currentFontSize;

    /**
     * @var array
     */
    protected $fonts;

    /**
     * Currently-applied opacity level (0 - 1)
     *
     * @var float
     */
    protected $currentOpacity = 1;

    /**
     * @var array
     */
    protected $currentLineTransparency = ['mode' => 'Normal', 'opacity' => 1.0];

    /**
     * @var array
     */
    protected $currentFillTransparency = ['mode' => 'Normal', 'opacity' => 1.0];

    /**
     * Class constructor
     *
     * @param mixed $paper The size of paper to use in this PDF ({@link CPDF::$PAPER_SIZES})
     * @param string $orientation The orientation of the document (either 'landscape' or 'portrait')
     * @param Dompdf $dompdf The Dompdf instance
     */
    public function __construct($paper = 'letter', $orientation = 'portrait', Dompdf $dompdf)
    {
        if (!class_exists(\SetaPDF_Core::class)) {
            throw new RuntimeException(
                'Missing dependency "SetaPDF-Core". SetaPDF-Core requires a commercial license '
                . '(or an evaluation license). More informations can be found here: '
                . 'https://www.setasign.com/products/setapdf-core/details/'
            );
        }
        if (\SetaPDF_Core::VERSION !== 'dev-trunk' && !version_compare(\SetaPDF_Core::VERSION, '2.35.0.1507', '>')) {
            throw new RuntimeException('Your SetaPDF-Core version is too low. You\'ll need at least 2.36.0.');
        }

        if (is_array($paper)) {
            $size = $paper;
        } elseif (isset(CPDF::$PAPER_SIZES[mb_strtolower($paper)])) {
            $size = CPDF::$PAPER_SIZES[mb_strtolower($paper)];
        } else {
            $size = CPDF::$PAPER_SIZES['letter'];
        }

        if (mb_strtolower($orientation) === 'landscape') {
            [$size[2], $size[3]] = [$size[3], $size[2]];
        }

        $this->dompdf = $dompdf;
        $this->width = $size[2] - $size[0];
        $this->height = $size[3] - $size[1];
        $this->orientation = $orientation;

        $this->document = new \SetaPDF_Core_Document(new \SetaPDF_Core_Writer_TempFile(
            $this->dompdf->getOptions()->getTempDir(),
            'libdompdf_setapdf_'
        ));
        $this->document->getCatalog()->getPages()->create([$this->width, $this->height], $orientation);
        // todo
//            $dompdf->getOptions()->getFontCache(),
//            $dompdf->getOptions()->getTempDir()

        $info = $this->document->getInfo();
        $info->setProducer(sprintf('%s + SetaPDF', $dompdf->version));
        $info->setCreationDate(new \DateTime());
        $info->setModDate(new \DateTime());

        $this->pageNumber = $this->pageCount = 1;
        $this->imageCache = [];
    }

    /**
     * @return \SetaPDF_Core_Document
     */
    public function getDocument()
    {
        return $this->document;
    }

    /**
     * @inheritDoc
     */
    public function get_dompdf()
    {
        return $this->dompdf;
    }

    /**
     * @inheritDoc
     */
    public function get_page_number()
    {
        return $this->pageNumber;
    }

    /**
     * @inheritDoc
     */
    public function get_page_count()
    {
        return $this->pageCount;
    }

    /**
     * Sets the current page number
     *
     * @param int $num
     */
    public function set_page_number($num)
    {
        $this->pageNumber = $num;
    }

    /**
     * Sets the page count
     *
     * @param int $count
     */
    public function set_page_count($count)
    {
        $this->pageCount = $count;
    }

    /**
     * Remaps y coords from 4th to 1st quadrant
     *
     * @param float $y
     * @return float
     */
    protected function y($y)
    {
        return $this->height - $y;
    }

    protected function setStrokingColor(\SetaPDF_Core_Canvas $canvas, $color)
    {
        $alpha = isset($color['alpha']) ? $color['alpha'] : 1;
        if ($this->currentOpacity != 1) {
            $alpha *= $this->currentOpacity;
        }

        $newColor = [
            (float) $color[0],
            (float) $color[1],
            (float) $color[2]
        ];
        if (isset($color[3])) {
            $newColor[] = (float) $color[3];
        }
        $newColor = \SetaPDF_Core_DataStructure_Color::createByComponents($newColor);
        if ($this->currentStrokingColor != $newColor) {
            $canvas->setStrokingColor($newColor);
            $this->currentStrokingColor = $newColor;
        }

        $this->setLineTransparency($canvas, 'Normal', $alpha);
    }

    protected function setLineStyle(\SetaPDF_Core_Canvas $canvas, $width, $cap, $join, $style)
    {
        $path = $canvas->path()->setLineWidth($width);

        switch ($cap) {
            case 'butt':
                $path->setLineCap(\SetaPDF_Core_Canvas_Path::LINE_CAP_BUTT);
                break;
            case 'round':
                $path->setLineCap(\SetaPDF_Core_Canvas_Path::LINE_CAP_ROUND);
                break;
            case 'square':
                $path->setLineCap(\SetaPDF_Core_Canvas_Path::LINE_CAP_PROJECTING_SQUARE);
                break;
        }

        switch ($join) {
            case 'miter':
                $path->setLineJoin(\SetaPDF_Core_Canvas_Path::LINE_JOIN_MITER);
                break;
            case 'round':
                $path->setLineJoin(\SetaPDF_Core_Canvas_Path::LINE_JOIN_ROUND);
                break;
            case 'bevel':
                $path->setLineJoin(\SetaPDF_Core_Canvas_Path::LINE_JOIN_BEVEL);
                break;
        }

        if (is_array($style)) {
            $path->setDashPattern($style);
        }
    }

    protected function setFillColor(\SetaPDF_Core_Canvas $canvas, $color)
    {
        $alpha = isset($color['alpha']) ? $color['alpha'] : 1;
        if ($this->currentOpacity != 1) {
            $alpha *= $this->currentOpacity;
        }

        $newColor = [
            (float) $color[0],
            (float) $color[1],
            (float) $color[2]
        ];
        if (isset($color[3])) {
            $newColor[] = (float) $color[3];
        }
        $newColor = \SetaPDF_Core_DataStructure_Color::createByComponents($newColor);
        if ($this->currentFillColor != $newColor) {
            $canvas->setNonStrokingColor($newColor);
            $this->currentFillColor = $newColor;
        }

        $this->setFillTransparency($canvas, 'Normal', $alpha);
    }

    protected function setLineTransparency(\SetaPDF_Core_Canvas $canvas, $mode, $opacity)
    {
        static $blend_modes = [
            'Normal',
            'Multiply',
            'Screen',
            'Overlay',
            'Darken',
            'Lighten',
            'ColorDogde',
            'ColorBurn',
            'HardLight',
            'SoftLight',
            'Difference',
            'Exclusion'
        ];

        if (!in_array($mode, $blend_modes)) {
            $mode = 'Normal';
        }

        // Only create a new graphics state if required
        if ($mode === $this->currentLineTransparency['mode'] && $opacity == $this->currentLineTransparency['opacity']) {
            return;
        }

        $this->currentLineTransparency['mode'] = $mode;
        $this->currentLineTransparency['opacity'] = $opacity;

        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $state = new \SetaPDF_Core_Resource_ExtGState();
        $state->setBlendMode($mode);
        $state->setConstantOpacity((float) $opacity);
        $canvas->setGraphicState($state, $this->document);
    }

    protected function setFillTransparency(\SetaPDF_Core_Canvas $canvas, $mode, $opacity)
    {
        static $blend_modes = [
            'Normal',
            'Multiply',
            'Screen',
            'Overlay',
            'Darken',
            'Lighten',
            'ColorDogde',
            'ColorBurn',
            'HardLight',
            'SoftLight',
            'Difference',
            'Exclusion'
        ];

        if (!in_array($mode, $blend_modes)) {
            $mode = 'Normal';
        }

        if ($mode === $this->currentFillTransparency['mode'] && $opacity == $this->currentFillTransparency['opacity']) {
            return;
        }

        $this->currentFillTransparency['mode'] = $mode;
        $this->currentFillTransparency['opacity'] = $opacity;

        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $state = new \SetaPDF_Core_Resource_ExtGState();
        $state->setBlendMode($mode);
        $state->setConstantOpacity((float) $opacity);
        $canvas->setGraphicState($state, $this->document);
    }

    /**
     * @inheritDoc
     */
    public function line($x1, $y1, $x2, $y2, $color, $width, $style = null)
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();

        $this->setStrokingColor($canvas, $color);
        $this->setLineStyle($canvas, $width, 'butt', '', $style);
        $path = $canvas->draw()->path();

        if (is_array($style)) {
            $path->setDashPattern($style);
        }
        $path->moveTo($x1, $this->y($y1))->lineTo($x2, $this->y($y2))->stroke();
        $this->setLineTransparency($canvas, 'Normal', $this->currentOpacity);
    }

    /**
     * @inheritDoc
     */
    public function rectangle($x1, $y1, $w, $h, $color, $width, $style = null)
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $this->setStrokingColor($canvas, $color);
        $this->setLineStyle($canvas, $width, 'butt', '', $style);

        $canvas->draw()->rect($x1, $this->y($y1) - $h, $w, $h);
        $this->setLineTransparency($canvas, 'Normal', $this->currentOpacity);
    }

    /**
     * @inheritDoc
     */
    public function filled_rectangle($x1, $y1, $w, $h, $color)
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $this->setFillColor($canvas, $color);
        $canvas->draw()->rect($x1, $this->y($y1) - $h, $w, $h, \SetaPDF_Core_Canvas_Draw::STYLE_FILL);
        $this->setFillTransparency($canvas, 'Normal', $this->currentOpacity);
    }

    /**
     * @inheritDoc
     */
    public function clipping_rectangle($x1, $y1, $w, $h)
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();

        $this->save();
        $canvas->path()->rect($x1, $this->y($y1) - $h, $w, $h)->clip()->endPath();
    }

    /**
     * @inheritDoc
     */
    public function clipping_roundrectangle($x1, $y1, $w, $h, $rTL, $rTR, $rBR, $rBL)
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $y1 = $this->y($y1) - $h;
        $this->save();

        $path = $canvas->path();
        // start: top edge, left end
        $path->moveTo($x1, $y1 - $rTL + $h);

        // line: bottom edge, left end
        $path->lineTo($x1, $y1 + $rBL);

        // curve: bottom-left corner
        $this->ellipse($canvas, $x1 + $rBL, $y1 + $rBL, $rBL, 0, 0, 8, 180, 270, false, false, false, true);

        // line: right edge, bottom end
        $path->lineTo($x1 + $w - $rBR, $y1);

        // curve: bottom-right corner
        $this->ellipse($canvas, $x1 + $w - $rBR, $y1 + $rBR, $rBR, 0, 0, 8, 270, 360, false, false, false, true);

        // line: right edge, top end
        $path->lineTo($x1 + $w, $y1 + $h - $rTR);

        // curve: bottom-right corner
        $this->ellipse($canvas, $x1 + $w - $rTR, $y1 + $h - $rTR, $rTR, 0, 0, 8, 0, 90, false, false, false, true);

        // line: bottom edge, right end
        $path->lineTo($x1 + $rTL, $y1 + $h);

        // curve: top-right corner
        $this->ellipse($canvas, $x1 + $rTL, $y1 + $h - $rTL, $rTL, 0, 0, 8, 90, 180, false, false, false, true);

        // line: top edge, left end
        $path->lineTo($x1 + $rBL, $y1);

        // Close & clip
        $path->clip()->endPath();
    }

    /**
     * @inheritDoc
     */
    public function clipping_end()
    {
        $this->restore();
    }

    /**
     * draw an ellipse
     * note that the part and filled ellipse are just special cases of this function
     *
     * draws an ellipse in the current line style
     * centered at $x0,$y0, radii $r1,$r2
     * if $r2 is not set, then a circle is drawn
     * from $astart to $afinish, measured in degrees, running anti-clockwise from the right hand side of the ellipse.
     * nSeg is not allowed to be less than 2, as this will simply draw a line (and will even draw a
     * pretty crappy shape at 2, as we are approximating with bezier curves.
     *
     * @param \SetaPDF_Core_Canvas $canvas
     * @param $x0
     * @param $y0
     * @param $r1
     * @param int $r2
     * @param int $angle
     * @param int $nSeg
     * @param int $astart
     * @param int $afinish
     * @param bool $close
     * @param bool $fill
     * @param bool $stroke
     * @param bool $incomplete
     */
    protected function ellipse(
        \SetaPDF_Core_Canvas $canvas,
        $x0,
        $y0,
        $r1,
        $r2 = 0,
        $angle = 0,
        $nSeg = 8,
        $astart = 0,
        $afinish = 360,
        $close = true,
        $fill = false,
        $stroke = true,
        $incomplete = false
    ) {
        // note: copied from cpdf to emulate the exact behavior
        if ($r1 == 0) {
            return;
        }

        if ($r2 == 0) {
            $r2 = $r1;
        }

        if ($nSeg < 2) {
            $nSeg = 2;
        }

        $astart = deg2rad((float)$astart);
        $afinish = deg2rad((float)$afinish);
        $totalAngle = $afinish - $astart;

        $dt = $totalAngle / $nSeg;
        $dtm = $dt / 3;

        if ($angle != 0) {
            $a = -1 * deg2rad((float)$angle);

            $canvas->addCurrentTransformationMatrix(cos($a), -sin($a), sin($a), cos($a), $x0, $y0);

            $x0 = 0;
            $y0 = 0;
        }

        $t1 = $astart;
        $a0 = $x0 + $r1 * cos($t1);
        $b0 = $y0 + $r2 * sin($t1);
        $c0 = -$r1 * sin($t1);
        $d0 = $r2 * cos($t1);

        $path = $canvas->path();
        if (!$incomplete) {
            $path->moveTo($a0, $b0);
        }

        for ($i = 1; $i <= $nSeg; $i++) {
            // draw this bit of the total curve
            $t1 = $i * $dt + $astart;
            $a1 = $x0 + $r1 * cos($t1);
            $b1 = $y0 + $r2 * sin($t1);
            $c1 = -$r1 * sin($t1);
            $d1 = $r2 * cos($t1);

            $path->curveTo(
                ($a0 + $c0 * $dtm),
                ($b0 + $d0 * $dtm),
                ($a1 - $c1 * $dtm),
                ($b1 - $d1 * $dtm),
                $a1,
                $b1
            );

            $a0 = $a1;
            $b0 = $b1;
            $c0 = $c1;
            $d0 = $d1;
        }

        if (!$incomplete) {
            if ($fill) {
                $path->fill();
            }

            if ($stroke) {
                if ($close) {
                    $path->closeAndStroke();
                } else {
                    $path->stroke();
                }
            }
        }

        if ($angle != 0) {
            $canvas->restoreGraphicState();
        }
    }

    public function getFont(string $font): ?\SetaPDF_Core_Font_FontInterface
    {
        if (!isset($this->fonts[$font])) {
            switch (basename($font)) {
                case 'Courier':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_Courier::create($this->document);
                    break;
                case 'Courier-Bold':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_CourierBold::create($this->document);
                    break;
                case 'Courier-BoldOblique':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_CourierBoldOblique::create($this->document);
                    break;
                case 'Courier-Oblique':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_CourierOblique::create($this->document);
                    break;
                case 'Helvetica':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_Helvetica::create($this->document);
                    break;
                case 'Helvetica-Bold':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_HelveticaBold::create($this->document);
                    break;
                case 'Helvetica-BoldOblique':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_HelveticaBoldOblique::create($this->document);
                    break;
                case 'Helvetica-Oblique':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_HelveticaOblique::create($this->document);
                    break;
                case 'Times-Bold':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_TimesBold::create($this->document);
                    break;
                case 'Times-BoldItalic':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_TimesBoldItalic::create($this->document);
                    break;
                case 'Times-Italic':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_TimesItalic::create($this->document);
                    break;
                case 'times':
                case 'Times-Roman':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_TimesRoman::create($this->document);
                    break;
                case 'Symbol':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_Symbol::create($this->document);
                    break;
                case 'ZapfDingbats':
                    $this->fonts[$font] = \SetaPDF_Core_Font_Standard_ZapfDingbats::create($this->document);
                    break;
                default:
                    // todo should we only use subsetting if enabled in options?
                    $this->fonts[$font] = new \SetaPDF_Core_Font_Type0_Subset($this->document, $font . '.ttf');
                    break;
            }
        }

        return $this->fonts[$font];
    }

    protected function setCurrentFont(\SetaPDF_Core_Canvas $canvas, \SetaPDF_Core_Font_FontInterface $font, $size)
    {
        if ($this->currentFont !== $font || $this->currentFontSize !== $size) {
            $canvas->draw()->text()->setFont($font, $size);
            $this->currentFont = $font;
            $this->currentFontSize = $size;
        }
    }

    /**
     * @inheritDoc
     */
    public function text(
        $x,
        $y,
        $text,
        $font,
        $size,
        $color = [0, 0, 0],
        $word_space = 0.0,
        $char_space = 0.0,
        $angle = 0.0
    ) {
        $font = $this->getFont($font);
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $this->setFillColor($canvas, $color);

        $this->setCurrentFont($canvas, $font, $size);
        $canvasText = $canvas->draw()->text()->begin();

        $h = $font->getAscent() - $font->getDescent();
        if ($font instanceof \SetaPDF_Core_Font_Type0_Subset) {
            /**
             * @var \SetaPDF_Core_Font_TrueType_Table_HorizontalHeader $hhea
             */
            $hhea = $font->getFontFile()->getTable(\SetaPDF_Core_Font_TrueType_Table_Tags::HORIZONTAL_HEADER);
            $h += $hhea->getLineGap();
        }

        $fontHeight =  $size * $h / 1000;

        if ($angle == 0) {
            $canvasText->moveToNextLine($x, $this->y($y) - $fontHeight);
        } else {
            $a = deg2rad((float)$angle);
            $canvasText->setTextMatrix(cos($a), -sin($a), sin($a), cos($a), $x, $this->y($y) - $fontHeight);
        }

        if ($word_space !== 0.0) {
            $canvasText->setWordSpacing($word_space);
        }

        if ($char_space !== 0.0) {
            $canvasText->setCharacterSpacing($char_space);
        }

        $canvasText->showText($font->getCharCodes($text, 'UTF-8'))->end();
    }

    /**
     * @inheritDoc
     */
    public function page_text(
        $x,
        $y,
        $text,
        $font,
        $size,
        $color = [0, 0, 0],
        $word_space = 0.0,
        $char_space = 0.0,
        $angle = 0.0
    ) {
        $this->pageText[] = [
            'type' => 'text',
            'x' => $x,
            'y' => $y,
            'text' => $text,
            'font' => $font,
            'size' => $size,
            'color' => $color,
            'word_space' => $word_space,
            'char_space' => $char_space,
            'angle' => $angle
        ];
    }

    /**
     * @inheritDoc
     */
    public function rotate($angle, $x, $y)
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();

        $y = $this->y($y);

        $a = deg2rad($angle);
        $cos_a = cos($a);
        $sin_a = sin($a);

        $canvas->addCurrentTransformationMatrix(
            $cos_a,
            -$sin_a,
            $sin_a,
            $cos_a,
            $x - $sin_a * $y - $cos_a * $x,
            $y - $cos_a * $y + $sin_a * $x
        );
    }

    /**
     * @inheritDoc
     */
    public function skew($angle_x, $angle_y, $x, $y)
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();

        $y = $this->y($y);

        $tan_x = tan(deg2rad($angle_x));
        $tan_y = tan(deg2rad($angle_y));

        $canvas->addCurrentTransformationMatrix(
            1,
            -$tan_y,
            -$tan_x,
            1,
            $tan_x * $y,
            $tan_y * $x
        );
    }

    /**
     * @inheritDoc
     */
    public function scale($s_x, $s_y, $x, $y)
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $y = $this->y($y);

        $canvas->addCurrentTransformationMatrix(
            $s_x,
            0,
            0,
            $s_y,
            $x * (1 - $s_x),
            $y * (1 - $s_y)
        );
    }

    /**
     * @inheritDoc
     */
    public function translate($t_x, $t_y)
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $canvas->addCurrentTransformationMatrix(
            1,
            0,
            0,
            1,
            $t_x,
            -$t_y
        );
    }

    /**
     * @inheritDoc
     */
    public function transform($a, $b, $c, $d, $e, $f)
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $canvas->addCurrentTransformationMatrix($a, $b, $c, $d, $e, $f);
    }

    /**
     * @inheritDoc
     */
    public function polygon($points, $color, $width = null, $style = null, $fill = false)
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        if ($fill) {
            $this->setFillColor($canvas, $color);
        } else {
            $this->setStrokingColor($canvas, $color);
            $this->setLineStyle($canvas, $width, 'butt', '', $style);
        }

        $canvas->draw()->polygon(
            $points,
            $fill ? \SetaPDF_Core_Canvas_Draw::STYLE_FILL : \SetaPDF_Core_Canvas_Draw::STYLE_DRAW
        );

        if ($fill) {
            $this->setFillTransparency($canvas, 'Normal', $this->currentOpacity);
        } else {
            $this->setLineTransparency($canvas, 'Normal', $this->currentOpacity);
        }
    }

    /**
     * @inheritDoc
     */
    public function circle($x, $y, $r, $color, $width = null, $lineStyle = null, $fill = false)
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $this->setFillColor($canvas, $color);
        $this->setStrokingColor($canvas, $color);

        if (!$fill && isset($width)) {
            $this->setLineStyle($canvas, $width, 'round', 'round', $lineStyle);
        }

        $style = $fill ? \SetaPDF_Core_Canvas_Draw::STYLE_DRAW_AND_FILL : \SetaPDF_Core_Canvas_Draw::STYLE_DRAW;
        $canvas->draw()->circle($x, $y, $r, $style);

        $this->setFillTransparency($canvas, 'Normal', $this->currentOpacity);
        $this->setLineTransparency($canvas, 'Normal', $this->currentOpacity);
    }

    /**
     * @inheritDoc
     */
    public function image($img_url, $x, $y, $w, $h, $resolution = 'normal')
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        [$width, $height, $type] = Helpers::dompdf_getimagesize($img_url, $this->dompdf->getHttpContext());

        $debug_png = $this->dompdf->getOptions()->getDebugPng();

        if ($debug_png) {
            print "[image:$img_url|$width|$height|$type]";
        }

        switch ($type) {
            case "jpeg":
            case "gif":
            case "bmp":
            case "png":
                $img_url = realpath($img_url);
                if (!array_key_exists($img_url, $this->imageCache)) {
                    $image = new \SetaPDF_Core_Reader_File($img_url);
                    $this->imageCache[$img_url] = \SetaPDF_Core_XObject_Image::create($this->document, $image);
                }

                $xObject = $this->imageCache[$img_url];
                $xObject->draw(
                    $canvas,
                    $x,
                    $this->y($y) - $h,
                    $w,
                    $h
                );
                break;

            case "svg":
                $img_url = realpath($img_url);
                if (!array_key_exists($img_url, $this->imageCache)) {
                    $doc = new \Svg\Document();
                    $doc->loadFile($img_url);
                    $dimensions = $doc->getDimensions();

                    $surface = new \Svg\Surface\SurfaceCpdf($doc, new \Dompdf\Cpdf([0, 0, $dimensions['width'], $dimensions['height']]));
                    $doc->render($surface);
                    $document = \SetaPDF_Core_Document::loadByString($surface->out());
                    $this->imageCache[$img_url] = $document->getCatalog()->getPages()->getPage(1)->toXObject($this->document);
                }

                $xObject = $this->imageCache[$img_url];
                $xObject->draw(
                    $canvas,
                    $x,
                    $this->y($y) - $h,
                    $w,
                    $h
                );
                break;

            // doesn't work because pdf files aren't a supported image type of dompdf
//            case 'pdf':
//                $img_url = realpath($img_url);
//                if (!array_key_exists($img_url, $this->imageCache)) {
//                    $template = \SetaPDF_Core_Document::loadByFilename(__DIR__ . '/template.pdf');
//                    $templatePages = $template->getCatalog()->getPages();
//                    $this->imageCache[$img_url] = $templatePages->getPage(1)->toXObject($this->document);
//                }
//
//                $xObject = $this->imageCache[$img_url];
//                $xObject->draw(
//                    $canvas,
//                    $x,
//                    $this->y($y) - $h,
//                    $w,
//                    $h
//                );
//                break;
        }
    }

    /**
     * @inheritDoc
     */
    public function arc($x, $y, $r1, $r2, $astart, $aend, $color, $width, $style = [])
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $this->setStrokingColor($canvas, $color);
        $this->setLineStyle($canvas, $width, 'butt', '', $style);
        $this->ellipse($canvas, $x, $this->y($y), $r1, $r2, 0, 8, $astart, $aend, false, false, true, false);
        $this->setLineTransparency($canvas, 'Normal', $this->currentOpacity);
    }

    /**
     * @inheritDoc
     */
    public function add_named_dest($anchorname)
    {
        $destinations = (
            $this->document->getCatalog()->getNames()->getTree(\SetaPDF_Core_Document_Catalog_Names::DESTS, true)
        );

        $destination = \SetaPDF_Core_Document_Destination::createByPageNo($this->document, $this->pageNumber);
        $destinations->add($anchorname, $destination->getPdfValue());
    }

    /**
     * @inheritDoc
     */
    public function add_link($url, $x, $y, $width, $height)
    {
        $page = $this->document->getCatalog()->getPages()->getPage($this->pageNumber);
        $y = $this->y($y) - $height;

        if (strpos($url, '#') === 0) {
            // Local link
            $name = substr($url, 1);

            if ($name) {
                $page->getAnnotations()->add(new \SetaPDF_Core_Document_Page_Annotation_Link(
                    \SetaPDF_Core_Document_Page_Annotation_Link::createAnnotationDictionary(
                        \SetaPDF_Core_DataStructure_Rectangle::create([$x, $y, $x + $width, $y + $height]),
                        new \SetaPDF_Core_Document_Action_GoTo(
                            \SetaPDF_Core_Document_Action_GoTo::createActionDictionary($name)
                        )
                    )
                ));
            }
        } else {
            $page->getAnnotations()->add(new \SetaPDF_Core_Document_Page_Annotation_Link(
                \SetaPDF_Core_Document_Page_Annotation_Link::createAnnotationDictionary(
                    \SetaPDF_Core_DataStructure_Rectangle::create([$x, $y, $x + $width, $y + $height]),
                    $url
                )
            ));
        }
    }

    /**
     * @inheritDoc
     */
    public function add_info($name, $value)
    {
        $this->document->getInfo()->setAll([$name, $value]);
    }

    /**
     * @inheritDoc
     */
    public function get_text_width($text, $font, $size, $word_spacing = 0.0, $char_spacing = 0.0)
    {
        $font = $this->getFont($font);

        $spacing = 0;
        if ($word_spacing != 0) {
            $spaces = substr_count($text, "\x00\x20");
            $spacing += $spaces * $word_spacing;
        }

        if ($char_spacing != 0) {
            $spacing += (\SetaPDF_Core_Encoding::strlen($text, 'UTF-8') - 1) * $char_spacing;
        }

        return $font->getGlyphsWidth($text, 'UTF-8') / 1000 * $size + $spacing;
    }

    /**
     * @inheritDoc
     */
    public function get_font_height($font, $size)
    {
        if (!$font instanceof \SetaPDF_Core_Font_FontInterface) {
            $font = $this->getFont($font);
        }
        $h = $font->getAscent() - $font->getDescent();
        if ($font instanceof \SetaPDF_Core_Font_Type0_Subset) {
            /**
             * @var \SetaPDF_Core_Font_TrueType_Table_HorizontalHeader $hhea
             */
            $hhea = $font->getFontFile()->getTable(\SetaPDF_Core_Font_TrueType_Table_Tags::HORIZONTAL_HEADER);
            $h += $hhea->getLineGap();
        }

        return $size * $h / 1000 * $this->dompdf->getOptions()->getFontHeightRatio();
    }

    /**
     * @inheritDoc
     */
    public function get_font_baseline($font, $size)
    {
        $ratio = $this->dompdf->getOptions()->getFontHeightRatio();
        return $this->get_font_height($font, $size) / $ratio;
    }

    /**
     * @inheritDoc
     */
    public function get_width()
    {
        return $this->width;
    }

    /**
     * @inheritDoc
     */
    public function get_height()
    {
        return $this->height;
    }

    /**
     * @inheritDoc
     */
    public function set_opacity($opacity, $mode = 'Normal')
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $this->setLineTransparency($canvas, $mode, $opacity);
        $this->setFillTransparency($canvas, $mode, $opacity);
        $this->currentOpacity = $opacity;
    }

    /**
     * @inheritDoc
     */
    public function set_default_view($view, $options = [])
    {
        $options = array_values($options);
        array_unshift($options, $view);

        $pageObject = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getPageObject();
        array_unshift($options, $pageObject);
        $destination = call_user_func_array(['\SetaPDF_Core_Document_Destination', 'createDestinationArray'], $options);
        $this->document->getCatalog()->setOpenAction(new \SetaPDF_Core_Document_Destination($destination));
    }

    /**
     * @inheritDoc
     */
    public function javascript($script)
    {
        // create an action
        $jsAction = new \SetaPDF_Core_Document_Action_JavaScript($script);

        // get names
        $names = $this->document->getCatalog()->getNames();
        // get the JavaScript name tree
        $javaScriptTree = $names->getTree(\SetaPDF_Core_Document_Catalog_Names::JAVA_SCRIPT, true);

        // make sure we've an unique name
        $name = 'SetaPDF';
        $i = 0;
        while ($javaScriptTree->get($name . ' ' . $i) !== false) {
            $i++;
        }

        // Add the JavaScript action to the document
        $javaScriptTree->add($name . ' ' . $i, $jsAction->getPdfValue());
    }

    protected function resetCurrentProperties()
    {
        $this->currentFontSize = null;
        $this->currentFont = null;
        $this->currentFillColor = null;
        $this->currentStrokingColor = null;
        $this->currentOpacity = 1;
        $this->currentLineTransparency = ['mode' => 'Normal', 'opacity' => 1.0];
        $this->currentFillTransparency = ['mode' => 'Normal', 'opacity' => 1.0];
    }

    /**
     * @inheritDoc
     */
    public function new_page()
    {
        $this->pageNumber++;
        $this->pageCount++;

        $this->resetCurrentProperties();
        return $this->document->getCatalog()->getPages()->create([$this->width, $this->height], $this->orientation);
    }

    /**
     * @inheritDoc
     */
    public function save()
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $canvas->saveGraphicState();

        $this->resetCurrentProperties();
    }

    /**
     * @inheritDoc
     */
    public function restore()
    {
        $canvas = $this->document->getCatalog()->getPages()->getPage($this->pageNumber)->getCanvas();
        $canvas->restoreGraphicState();

        $this->resetCurrentProperties();
    }

    protected function writePageContent()
    {
        if (count($this->pageText) === 0) {
            return;
        }

        for ($i = 1; $i <= $this->pageCount; $i++) {
            $this->pageNumber = $i;
            $this->resetCurrentProperties();

            foreach ($this->pageText as $item) {
                switch ($item['type']) {
                    case 'text':
                        $text = str_replace(
                            ["{PAGE_NUM}", "{PAGE_COUNT}"],
                            [$i, $this->pageCount],
                            $item['text']
                        );
                        $this->text(
                            $item['x'],
                            $item['y'],
                            $text,
                            $item['font'],
                            $item['size'],
                            $item['color'],
                            $item['word_space'],
                            $item['char_space'],
                            $item['angle']
                        );
                        break;

                    // todo implement page_script?
//                    case "script":
//                        if (!$eval) {
//                            $eval = new PhpEvaluator($this);
//                        }
//                        $eval->evaluate($code, ['PAGE_NUM' => $page_number, 'PAGE_COUNT' => $this->_page_count]);
//                        break;
//
//                    case 'line':
//                        $this->line( $x1, $y1, $x2, $y2, $color, $width, $style );
//                        break;
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function stream($filename, $options = [])
    {
        if (headers_sent()) {
            die('Unable to stream pdf: headers already sent');
        }

        if (!isset($options['Attachment'])) {
            $options['Attachment'] = true;
        }

        if ($this->document->getState() === \SetaPDF_Core_Document::STATE_NONE) {
            $this->writePageContent();
            $this->document->save()->finish();
        }

        $tmp = file_get_contents($this->document->getWriter()->getPath());

        header('Cache-Control: private');
        header('Content-Type: application/pdf');
        header('Content-Length: ' . mb_strlen($tmp, '8bit'));

        $filename = str_replace(["\n", "'"], '', basename($filename, '.pdf')) . '.pdf';
        $attachment = $options['Attachment'] ? 'attachment' : 'inline';
        header(Helpers::buildContentDispositionHeader($attachment, $filename));

        echo $tmp;
        flush();
    }

    /**
     * @inheritDoc
     */
    public function output($options = [])
    {
        if ($this->document->getState() === \SetaPDF_Core_Document::STATE_NONE) {
            $this->writePageContent();
            $this->document->save()->finish();
        }

        return file_get_contents($this->document->getWriter()->getPath());
    }
}
