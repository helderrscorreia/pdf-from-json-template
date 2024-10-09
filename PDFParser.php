<?php
/**
 * Class PDFParser
 * @description Transform a JSON object defined structure into PDF document.
 * @author Hélder Correia
 *
 * https://www.goldylocks.pt
 * Goldylocks Portugal
 */

// include TCPDF
include_once(dirname(__FILE__) . '/../../tcpdf/tcpdf_import.php');

class PDFParser
{
    private $jsonTemplate;
    private $pdf;
    private $fileName;
    private $outputType = "I";
    private $data = [];
    private $cacheFolder = __DIR__ . "/pdf_cache/";
    private $currentDetailsDataField = NULL;
    private $previousDetailsDataField = NULL;
    private $templateVariables = [];
    private $details = [];
    private $maxDetailsY = [];
    private $lastGroupHeaders = [];
    private $printGroupHeader = [];
    private $pageCount = 0;
    private $documentCopies = 1;
    private $currentDocumentCopy = 1;
    private $lastFont = 'times';
    private $lastFontSize = 8;
    private $lastShowIf = null;
    private $designMode = false;
    private $detailsCurrentX = 0;
    private $detailsCurrentY = 0;
    private $currentDetailsBaseY = 0; /* register the initial Y for each details row */
    private $storedPositions = []; /* array of stored positions to be used */
    private $transactionStartedComponent = null;

    public function clearCache()
    {
        $files = glob("$this->cacheFolder*"); // get all file names
        foreach ($files as $file) { // iterate files
            if (is_file($file)) {
                unlink($file); // delete file
            }
        }
    }

    public function __construct($jsonTemplate, $fileName = "document.pdf", $designMode = false)
    {
        $this->jsonTemplate = $jsonTemplate;
        $this->fileName = $fileName;
        $this->designMode = $designMode;
    }

    /**
     * get JSON template
     * @return string
     */
    public function getJsonTemplate()
    {
        return $this->jsonTemplate;
    }

    /**
     * set data array to be used
     * @param $dataArray
     */
    public function setData($dataArray)
    {
        $this->data = $dataArray;
        return $this;
    }

    /**
     * @param int $documentCopies
     */
    public function setDocumentCopies($documentCopies = 1)
    {
        $this->documentCopies = $documentCopies;
    }

    /**
     * convert hex color to RGB format used by TCPDF
     * @param $hexColor
     * @return array
     */
    private function convertHexToRGBColor($hexColor)
    {
        $split = str_split(str_replace('#', '', $hexColor), 2);
        $r = hexdec($split[0]);
        $g = hexdec($split[1]);
        $b = hexdec($split[2]);

        return [$r, $g, $b];
    }

    /**
     * parse global variables
     * @param $string
     * @return array|string|string[]
     */
    public function parseGlobalVariables($string, $curlyBraces = true, $returnEmpty = false)
    {
        $initialString = $string;

        $curlyBeginning = ($curlyBraces === true) ? "{{" : "";
        $curlyEnding = ($curlyBraces === true) ? "}}" : "";
        // page numbers
        $string = str_replace("{$curlyBeginning}page_number{$curlyEnding}", $this->pdf->getAliasNumPage(), $string);
        $string = str_replace("{$curlyBeginning}total_pages{$curlyEnding}", $this->pdf->getAliasNbPages(), $string);

        // system variables
        $string = str_replace("{$curlyBeginning}current_date{$curlyEnding}", date('Y-m-j'), $string);
        $string = str_replace("{$curlyBeginning}current_year{$curlyEnding}", date('Y'), $string);
        $string = str_replace("{$curlyBeginning}current_month{$curlyEnding}", date('m'), $string);
        $string = str_replace("{$curlyBeginning}current_day{$curlyEnding}", date('j'), $string);
        $string = str_replace("{$curlyBeginning}current_time{$curlyEnding}", date('H:i:s'), $string);

        // document copies
        $string = str_replace("{$curlyBeginning}current_copy{$curlyEnding}", $this->currentDocumentCopy, $string);
        $string = str_replace("{$curlyBeginning}document_copies{$curlyEnding}", $this->documentCopies, $string);

        // template variables
        foreach ($this->templateVariables as $key => $value) {
            $string = str_replace("[[$key]]", $value, $string);
        }

        if ($returnEmpty && $string === $initialString) {
            return "";
        } else {
            return $string;
        }

    }

    /**
     * parse data in strings
     * @param $string
     * @param array $dataArray
     * @return array|string|string[]|null
     */
    public function parseStringData($string, $dataArray = [])
    {
        if ($this->designMode) {
            return $string;
        }

        // parse global variables
        $string = $this->parseGlobalVariables($string);

        // details variables
        $string = preg_replace_callback('/{{([\d\w\-\_\.]+)}}/', function ($match) use ($dataArray) {
            return $this->getDataField(str_replace(" ", "", $match[1]), $dataArray);
        }, $string);

        // global variables
        return preg_replace_callback('/{{([\d\w\-\_\.]+)}}/', function ($match) {
            return $this->getDataField(str_replace(" ", "", $match[1]), $this->data);
        }, $string);

    }

    /**
     * get data field value
     * @param $fieldPath
     * @return array|mixed
     */
    public function getDataField($fieldPath, $dataArray = [], $forceParse = false)
    {
        if ($this->designMode && !$forceParse) {
            return "[$fieldPath]";
        }

        $explodedPath = explode('.', $fieldPath);

        $data = $this->data;

        if (!empty($dataArray)) {
            // parameter data
            $data = array_merge($data, $dataArray);
        } elseif (isset($this->details[$this->currentDetailsDataField][0][$explodedPath[0]])) {
            // details data
            $data = array_merge($data, $this->details[$this->currentDetailsDataField][0]);
        }

        if (sizeof($explodedPath) === 1) {
            return $data[$explodedPath[0]] ?? "";
        } else if (sizeof($explodedPath) === 2) {
            return $data[$explodedPath[0]][$explodedPath[1]] ?? "";
        } else if (sizeof($explodedPath) === 3) {
            return $data[$explodedPath[0]][$explodedPath[1]][$explodedPath[2]] ?? "";
        } else if (sizeof($explodedPath) === 4) {
            return $data[$explodedPath[0]][$explodedPath[1]][$explodedPath[2]][$explodedPath[3]] ?? "";
        } else {
            return $data;
        }
    }

    /**
     * returns true if details are empty
     * must be used only after details components
     * @return bool
     */
    protected function isLastPage(): bool
    {
        return (empty($this->details));
    }

    /**
     * set pdf page properties
     * @param $obj
     */
    protected function renderPage($obj)
    {

        // instantiate TCPDF
        if (!isset($this->pdf)) {

            // page properties
            $pageSetup = [
                "orientation" => $obj['options']['orientation'] ?? "P",
                "unit" => $obj['options']['unit'] ?? "mm",
                "format" => $obj['options']['format'] ?? "A4",
                "encoding" => $obj['options']['encoding'] ?? "UTF-8",
                "printHeader" => $obj['options']['printHeader'] ?? false,
                "printFooter" => $obj['options']['printFooter'] ?? false,
                "autoPageBreak" => $obj['options']['autoPageBreak'] ?? true,
                "topMargin" => $obj['options']['topMargin'] ?? 10,
                "leftMargin" => $obj['options']['leftMargin'] ?? 10,
                "rightMargin" => $obj['options']['rightMargin'] ?? 10,
                "keepMargins" => $obj['options']['keepMargins'] ?? true,

            ];

            // parse format array
            if ($pageSetup['format'][0] === "[") {
                $f = str_replace('[', '', $pageSetup['format']);
                $f = str_replace(']', '', $f);
                $pageSetup['format'] = explode(',', $f);
            }

            // instantiate TCPDF
            $this->pdf = new TCPDF($pageSetup['orientation'], $pageSetup['unit'], $pageSetup['format'], $pageSetup['encoding']);

            // disable header and footer
            $this->pdf->SetAuthor('Goldylocks');
            $this->pdf->setPrintHeader($pageSetup['printHeader']);
            $this->pdf->setPrintFooter($pageSetup['printFooter']);
            $this->pdf->setAutoPageBreak($pageSetup['autoPageBreak']);
            $this->pdf->setMargins($pageSetup['leftMargin'], $pageSetup['topMargin'], $pageSetup['rightMargin'], $pageSetup['keepMargins']);
        }

        // add new page
        $this->pdf->AddPage();

        /*  clear position placholders */
        $this->maxDetailsY = [];
        $this->detailsCurrentY = $this->detailsCurrentX = 0;

        // increment page count
        $this->pageCount++;
    }

    /**
     * render text element
     * @param $obj
     */
    protected function renderText($obj, $dataArray = [])
    {
        // parse relative positioning
        // dx
        $defaultX = $this->pdf->getX();
        if (isset($obj['options']['dx'])) {
            $defaultX += $obj['options']['dx'];
        }

        // dy
        $defaultY = $this->pdf->getY();
        if (isset($obj['options']['dy'])) {
            $defaultY += $obj['options']['dy'];
        }

        // details relative X and Y
        if (isset($obj['options']['detailsX'])) {
            $defaultX = $this->detailsCurrentX + $obj['options']['detailsX'];
        }
        if (isset($obj['options']['detailsY'])) {
            $defaultY = $this->detailsCurrentY + $obj['options']['detailsY'];
        }

        // use stored X and Y positions
        if (isset($obj['options']['storedX'])) {
            $defaultX = $this->storedPositions[$obj['options']['storedX']]['x'];
        }
        if (isset($obj['options']['storedY'])) {
            $defaultY = $this->storedPositions[$obj['options']['storedY']]['y'];
        }

        $textOptions = [
            "x" => $obj['options']['x'] ?? $defaultX,
            "y" => $obj['options']['y'] ?? $defaultY,
            "color" => $obj['options']['color'] ?? [0, 0, 0],
            "bg-color" => $obj['options']['bg-color'] ?? null,
            "font-size" => $obj['options']['font-size'] ?? 12,
            "font-family" => $obj['options']['font-family'] ?? $this->lastFont,
            "text-decoration" => $obj['options']['text-decoration'] ?? '',
            "rotation" => $obj['options']['rotation'] ?? 0,
            "round" => $obj['options']['round'] ?? null,
            "utf8" => $obj['options']['utf8'] ?? false,
            "html_decoding" => $obj['options']['html_decoding'] ?? true,
            "group-header" => $obj['options']['group-header'] ?? false,
        ];

        // check if group header can be printed
        if ($textOptions['group-header'] === true && isset($this->printGroupHeader[$this->currentDetailsDataField]) && !$this->printGroupHeader[$this->currentDetailsDataField]) {
            return false;
        }

        // process color
        if ($textOptions['color'][0] === "#") {
            $textOptions['color'] = $this->convertHexToRGBColor($textOptions['color']);
        }

        // activate fill color
        if ($textOptions['bg-color'] !== null) {

            if ($textOptions['bg-color'][0] === "#") {
                $textOptions['bg-color'] = $this->convertHexToRGBColor($textOptions['bg-color']);
            }

            $this->pdf->SetFillColor($textOptions['bg-color'][0], $textOptions['bg-color'][1], $textOptions['bg-color'][2]);
            $useFillColor = true;
        } else {
            $useFillColor = false;
        }

        // text parse
        $data = isset($obj['data']) ? $this->getDataField($obj['data'], $dataArray) : "";

        // round data field
        $data = (is_numeric($data) && $textOptions['round'] !== null) ? number_format($data, $textOptions['round'], ",", ".") : $data;

        $text = isset($obj['text']) ? $this->parseStringData($obj['text'], $dataArray) : "";

        $this->pdf->SetTextColor($textOptions['color'][0], $textOptions['color'][1], $textOptions['color'][2]);
        $this->pdf->SetFont($textOptions['font-family'], $textOptions['text-decoration'], $textOptions['font-size']);

        // set XY position
        if ($textOptions['y'] === 0) {
            $posY = $this->pdf->getY();
        } else {
            $posY = $textOptions['y'];
        }

        if ($textOptions['x'] === 0) {
            $posX = $this->pdf->getX();
        } else {
            $posX = $textOptions['x'];
        }

        // process content to show
        // replace %s as the variable
        $content = $text . $data;
        if (strlen(trim($text)) > 0) {
            if (strpos($text, '%d') !== -1) {
                $content = str_replace('%d', $data, $text);
            }
        }

        // UTF-8 encoding
        if ($textOptions['utf8']) $content = utf8_encode($content);

        // HTML entities decoding
        if ($textOptions['html_decoding']) $content = html_entity_decode($content);

        $this->pdf->StartTransform();
        $this->pdf->Rotate($textOptions['rotation']);
        $this->pdf->Text($posX, $posY, $content, 0, false, true, 0, 0, "L", $useFillColor);
        $this->pdf->StopTransform();

    }

    protected function renderCell($obj, $dataArray = [])
    {

        // parse relative positioning
        // dx
        $defaultX = $this->pdf->getX();
        if (isset($obj['options']['dx'])) {
            $defaultX += $obj['options']['dx'];
        }

        // dy
        $defaultY = $this->pdf->getY();
        if (isset($obj['options']['dy'])) {
            $defaultY += $obj['options']['dy'];
        }

        /* set Y as details row initial Y */
        if (isset($obj['options']['detailsBaseY'])) {
            $defaultY = $this->currentDetailsBaseY + $obj['options']['detailsBaseY'];
        }

        // details relative X and Y
        if (isset($obj['options']['detailsX'])) {
            $defaultX = $this->detailsCurrentX + $obj['options']['detailsX'];
        }
        if (isset($obj['options']['detailsY'])) {
            $defaultY = $this->detailsCurrentY + $obj['options']['detailsY'];
        }

        // use stored X and Y positions
        if (isset($obj['options']['storedX'])) {
            $defaultX = $this->storedPositions[$obj['options']['storedX']]['x'];
        }
        if (isset($obj['options']['storedY'])) {
            $defaultY = $this->storedPositions[$obj['options']['storedY']]['y'];
        }

        $cellOptions = [
            "x" => $obj['options']['x'] ?? $defaultX,
            "y" => $obj['options']['y'] ?? $defaultY,
            "width" => $obj['options']['width'] ?? 50,
            "height" => $obj['options']['height'] ?? 5,
            "color" => $obj['options']['color'] ?? [0, 0, 0],
            "bg-color" => $obj['options']['bg-color'] ?? null,
            "font-size" => $obj['options']['font-size'] ?? 12,
            "font-family" => $obj['options']['font-family'] ?? $this->lastFont,
            "text-decoration" => $obj['options']['text-decoration'] ?? '',
            "text-align" => $obj['options']['text-align'] ?? 'L',
            "border" => $obj['options']['border'] ?? '',
            "multiline" => $obj['options']['multiline'] ?? false,
            "multiline-break" => $obj['options']['multiline-break'] ?? 0,
            "rotation" => $obj['options']['rotation'] ?? 0,
            "round" => $obj['options']['round'] ?? null,
            "utf8" => $obj['options']['utf8'] ?? false,
            "html_decoding" => $obj['options']['html_decoding'] ?? true,
            "group-header" => $obj['options']['group-header'] ?? false,
            "auto-width" => $obj['options']['auto-width'] ?? false,
        ];

        // check if group header can be printed
        if ($cellOptions['group-header'] === true && isset($this->printGroupHeader[$this->currentDetailsDataField]) && !$this->printGroupHeader[$this->currentDetailsDataField]) {
            return false;
        }

        // process color
        if ($cellOptions['color'][0] === "#") {
            $cellOptions['color'] = $this->convertHexToRGBColor($cellOptions['color']);
        }

        if ($cellOptions['bg-color'] !== null && $cellOptions['bg-color'][0] === "#") {
            $cellOptions['bg-color'] = $this->convertHexToRGBColor($cellOptions['bg-color']);
        }

        // text or data
        $data = isset($obj['data']) ? $this->getDataField($obj['data'], $dataArray) : "";

        // round data field
        $data = (is_numeric($data) && $cellOptions['round'] !== null) ? number_format($data, $cellOptions['round'], ",", ".") : $data;

        // parse text data
        $text = isset($obj['text']) ? $this->parseStringData($obj['text'], $dataArray) : "";

        // set font parameters
        $this->pdf->SetFont($cellOptions['font-family'], $cellOptions['text-decoration'], $cellOptions['font-size']);
        $this->pdf->SetTextColor($cellOptions['color'][0], $cellOptions['color'][1], $cellOptions['color'][2]);

        // activate fill color
        if ($cellOptions['bg-color'] !== null) {
            $this->pdf->SetFillColor($cellOptions['bg-color'][0], $cellOptions['bg-color'][1], $cellOptions['bg-color'][2]);
            $useFillColor = true;
        } else {
            $useFillColor = false;
        }

        // set XY position
        if ($cellOptions['y'] !== 0) {
            $this->pdf->SetY($cellOptions['y']);
        }
        if ($cellOptions['x'] !== 0) {
            $this->pdf->SetX($cellOptions['x']);
        }

        // process content to show
        // replace %s as the variable
        $content = $text . $data;
        if (strlen(trim($text)) > 0) {
            if (strpos($text, '%d') !== -1) {
                if (is_string($data)) {
                    $content = str_replace('%d', $data, $text);
                }
            }
        }

        // UTF-8 encoding
        if ($cellOptions['utf8']) $content = utf8_encode($content);

        // HTML entities decoding
        if ($cellOptions['html_decoding']) $content = html_entity_decode($content);

        /* calculate width */
        if ($cellOptions['auto-width']) {
            $size = strlen($content);
            $letterSize = $cellOptions['font-size'] * 0.18;
            $width = $size * $letterSize;
        } else {
            $width = $cellOptions['width'];
        }

        // render cell
        if ($cellOptions['multiline'] === true) {
            $this->pdf->MultiCell($width, $cellOptions['height'], $content, $cellOptions['border'], $cellOptions['text-align'], $useFillColor, $cellOptions['multiline-break']);
        } else {
            $this->pdf->StartTransform();
            $this->pdf->Rotate($cellOptions['rotation']);
            $this->pdf->Cell($width, $cellOptions['height'], $content, $cellOptions['border'], 0, $cellOptions['text-align'], $useFillColor);
            $this->pdf->StopTransform();
        }

        // reset fill color
        $this->pdf->SetFillColor(255, 255, 255);
    }

    protected function renderImage($obj, $dataArray = [])
    {
        // parse relative positioning
        // dx
        $defaultX = $this->pdf->getX();
        if (isset($obj['options']['dx'])) {
            $defaultX += $obj['options']['dx'];
        }

        // dy
        $defaultY = $this->pdf->getY();
        if (isset($obj['options']['dy'])) {
            $defaultY += $obj['options']['dy'];
        }

        // details relative X and Y
        if (isset($obj['options']['detailsX'])) {
            $defaultX = $this->detailsCurrentX + $obj['options']['detailsX'];
        }
        if (isset($obj['options']['detailsY'])) {
            $defaultY = $this->detailsCurrentY + $obj['options']['detailsY'];
        }

        // use stored X and Y positions
        if (isset($obj['options']['storedX'])) {
            $defaultX = $this->storedPositions[$obj['options']['storedX']]['x'];
        }
        if (isset($obj['options']['storedY'])) {
            $defaultY = $this->storedPositions[$obj['options']['storedY']]['y'];
        }

        $imageOptions = [
            "x" => $obj['options']['x'] ?? $defaultX,
            "y" => $obj['options']['y'] ?? $defaultY,
            "width" => $obj['options']['width'] ?? null,
            "height" => $obj['options']['height'] ?? null,
            "border" => $obj['options']['border'] ?? 0,
            "dpi" => $obj['options']['dpi'] ?? 300,
            "resize" => $obj['options']['resize'] ?? false,
            "link" => $obj['options']['link'] ?? "",
            "align" => $obj['options']['align'] ?? "",
            "palign" => $obj['options']['palign'] ?? "",
            "fitbox" => $obj['options']['fitbox'] ?? false,
            "group-header" => $obj['options']['group-header'] ?? false,
            "use-cache" => $obj['options']['use-cache'] ?? true,
        ];

        // check if group header can be printed
        if ($imageOptions['group-header'] === true && isset($this->printGroupHeader[$this->currentDetailsDataField]) && !$this->printGroupHeader[$this->currentDetailsDataField]) {
            return false;
        }

        // parse image path variables
        $imgSrc = $this->parseStringData($obj['src'], $dataArray);

        /* IMAGE CACHE MANAGEMENT */
        // image cache is enabled by default
        if ($imageOptions['use-cache']) {
            $imageHash = sha1($imgSrc);
            $cacheFilePath = $this->cacheFolder . $imageHash;

            // check if cache folder exists
            if (!file_exists($this->cacheFolder)) {
                mkdir($this->cacheFolder, 0777, true);
            }

            if (file_exists($cacheFilePath)) {
                // set imagepath as the local cache file
                $imgSrc = $cacheFilePath;
            } else {
                // download file to cache using cURL
                $my_ch = curl_init($imgSrc);
                $fp = fopen($cacheFilePath, 'wb');
                curl_setopt($my_ch, CURLOPT_FILE, $fp);
                curl_setopt($my_ch, CURLOPT_HEADER, 0);
                curl_exec($my_ch);
                curl_close($my_ch);
                fclose($fp);
            }
        }

        // if image URL not empty renders the image
        if (strlen($imgSrc) > 0) {
            $this->pdf->Image($imgSrc, $imageOptions['x'], $imageOptions['y'], $imageOptions['width'], $imageOptions['height'], '', $imageOptions['link'], $imageOptions['align'], $imageOptions['resize'], $imageOptions['dpi'], $imageOptions['palign'], false, false, $imageOptions['border'], $imageOptions['fitbox'], false, false);
            return true;
        } else {
            return false;
        }
    }

    protected function renderBox($obj)
    {
        // parse relative positioning
        // dx
        $defaultX = $this->pdf->getX();
        if (isset($obj['options']['dx'])) {
            $defaultX += $obj['options']['dx'];
        }

        // dy
        $defaultY = $this->pdf->getY();
        if (isset($obj['options']['dy'])) {
            $defaultY += $obj['options']['dy'];
        }

        // details relative X and Y
        if (isset($obj['options']['detailsX'])) {
            $defaultX = $this->detailsCurrentX + $obj['options']['detailsX'];
        }
        if (isset($obj['options']['detailsY'])) {
            $defaultY = $this->detailsCurrentY + $obj['options']['detailsY'];
        }

        // use stored X and Y positions
        if (isset($obj['options']['storedX'])) {
            $defaultX = $this->storedPositions[$obj['options']['storedX']]['x'];
        }
        if (isset($obj['options']['storedY'])) {
            $defaultY = $this->storedPositions[$obj['options']['storedY']]['y'];
        }

        $boxOptions = [
            "x" => $obj['options']['x'] ?? $defaultX,
            "y" => $obj['options']['y'] ?? $defaultY,
            "width" => $obj['options']['width'] ?? 0,
            "height" => $obj['options']['height'] ?? 0,
            "border-width" => $obj['options']['border-width'] ?? 0.1,
            "border-color" => $obj['options']['border-color'] ?? [0, 0, 0],
            "fill-color" => $obj['options']['fill-color'] ?? [255, 255, 255],
            "group-header" => $obj['options']['group-header'] ?? false,
        ];

        // check if group header can be printed
        if ($boxOptions['group-header'] === true && isset($this->printGroupHeader[$this->currentDetailsDataField]) && !$this->printGroupHeader[$this->currentDetailsDataField]) {
            return false;
        }

        $this->pdf->SetLineWidth($boxOptions['border-width']);
        $this->pdf->SetDrawColor($boxOptions['border-color'][0], $boxOptions['border-color'][1], $boxOptions['border-color'][2]);
        $this->pdf->SetFillColor($boxOptions['fill-color'][0], $boxOptions['fill-color'][1], $boxOptions['fill-color'][2]);

        $this->pdf->Rect($boxOptions['x'], $boxOptions['y'], $boxOptions['width'], $boxOptions['height'], 'DF');
    }

    protected function renderEllipse($obj)
    {
        // parse relative positioning
        // dx
        $defaultX = $this->pdf->getX();
        if (isset($obj['options']['dx'])) {
            $defaultX += $obj['options']['dx'];
        }

        // dy
        $defaultY = $this->pdf->getY();
        if (isset($obj['options']['dy'])) {
            $defaultY += $obj['options']['dy'];
        }

        // details relative X and Y
        if (isset($obj['options']['detailsX'])) {
            $defaultX = $this->detailsCurrentX + $obj['options']['detailsX'];
        }
        if (isset($obj['options']['detailsY'])) {
            $defaultY = $this->detailsCurrentY + $obj['options']['detailsY'];
        }

        // use stored X and Y positions
        if (isset($obj['options']['storedX'])) {
            $defaultX = $this->storedPositions[$obj['options']['storedX']]['x'];
        }
        if (isset($obj['options']['storedY'])) {
            $defaultY = $this->storedPositions[$obj['options']['storedY']]['y'];
        }

        $boxOptions = [
            "x" => $obj['options']['x'] ?? $defaultX,
            "y" => $obj['options']['y'] ?? $defaultY,
            "width" => $obj['options']['width'] ?? 0,
            "height" => $obj['options']['height'] ?? 0,
            "border-width" => $obj['options']['border-width'] ?? 0.1,
            "border-color" => $obj['options']['border-color'] ?? [0, 0, 0],
            "fill-color" => $obj['options']['fill-color'] ?? [255, 255, 255],
            "group-header" => $obj['options']['group-header'] ?? false,
        ];

        // check if group header can be printed
        if ($boxOptions['group-header'] === true && isset($this->printGroupHeader[$this->currentDetailsDataField]) && !$this->printGroupHeader[$this->currentDetailsDataField]) {
            return false;
        }

        $this->pdf->SetLineWidth($boxOptions['border-width']);
        $this->pdf->SetDrawColor($boxOptions['border-color'][0], $boxOptions['border-color'][1], $boxOptions['border-color'][2]);
        $this->pdf->SetFillColor($boxOptions['fill-color'][0], $boxOptions['fill-color'][1], $boxOptions['fill-color'][2]);

        $this->pdf->Ellipse($boxOptions['x'], $boxOptions['y'], $boxOptions['width'], $boxOptions['height']);
    }

    protected function renderLine($obj)
    {
        $lineOptions = [
            "x1" => $obj['options']['x1'] ?? 0,
            "y1" => $obj['options']['y1'] ?? 0,
            "x2" => $obj['options']['x2'] ?? 0,
            "y2" => $obj['options']['y2'] ?? 0,
            "width" => $obj['options']['width'] ?? 0.1,
            "color" => $obj['options']['color'] ?? [0, 0, 0],
            "group-header" => $obj['options']['group-header'] ?? false,
        ];

        // check if group header can be printed
        if ($lineOptions['group-header'] === true && isset($this->printGroupHeader[$this->currentDetailsDataField]) && !$this->printGroupHeader[$this->currentDetailsDataField]) {
            return false;
        }

        $this->pdf->SetLineWidth($lineOptions['width']);
        $this->pdf->SetDrawColor($lineOptions['color'][0], $lineOptions['color'][1], $lineOptions['color'][2]);

        $this->pdf->Line($lineOptions['x1'], $lineOptions['y1'], $lineOptions['x2'], $lineOptions['y2']);
    }

    protected function render1DBarcode($obj, $data = [])
    {
        // set image scale factor
        $this->pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // parse relative positioning
        // dx
        $defaultX = $this->pdf->getX();
        if (isset($obj['options']['dx'])) {
            $defaultX += $obj['options']['dx'];
        }

        // dy
        $defaultY = $this->pdf->getY();
        if (isset($obj['options']['dy'])) {
            $defaultY += $obj['options']['dy'];
        }

        // details relative X and Y
        if (isset($obj['options']['detailsX'])) {
            $defaultX = $this->detailsCurrentX + $obj['options']['detailsX'];
        }
        if (isset($obj['options']['detailsY'])) {
            $defaultY = $this->detailsCurrentY + $obj['options']['detailsY'];
        }

        // use stored X and Y positions
        if (isset($obj['options']['storedX'])) {
            $defaultX = $this->storedPositions[$obj['options']['storedX']]['x'];
        }
        if (isset($obj['options']['storedY'])) {
            $defaultY = $this->storedPositions[$obj['options']['storedY']]['y'];
        }

        // options
        $options = [
            "x" => $obj['options']['x'] ?? $defaultX,
            "y" => $obj['options']['y'] ?? $defaultY,
            "width" => $obj['options']['width'] ?? 10,
            "height" => $obj['options']['height'] ?? 10,
            "xres" => $obj['options']['xres'] ?? 0.4,
            "align" => $obj['options']['align'] ?? "N",
            "group-header" => $obj['options']['group-header'] ?? false,
            "rotation" => $obj['options']['rotation'] ?? 0,
        ];

        // check if group header can be printed
        if ($options['group-header'] === true && isset($this->printGroupHeader[$this->currentDetailsDataField]) && !$this->printGroupHeader[$this->currentDetailsDataField]) {
            return false;
        }
        // style parameters
        $style = [
            "type" => $obj['options']['type'] ?? "EAN13",
            "position" => $obj['options']['position'] ?? "",
            "align" => $obj['options']['textalign'] ?? "C",
            "stretch" => $obj['options']['stretch'] ?? false,
            "fitwidth" => $obj['options']['fitwidth'] ?? true,
            "cellfitalign" => $obj['options']['cellfitalign'] ?? "",
            "border" => $obj['options']['border'] ?? true,
            "hpadding" => $obj['options']['hpadding'] ?? "auto",
            "vpadding" => $obj['options']['vpadding'] ?? "auto",
            "fgcolor" => $obj['options']['fgcolor'] ?? [0, 0, 0],
            "bgcolor" => $obj['options']['bgcolor'] ?? false,
            "text" => $obj['options']['text'] ?? true,
            "font" => $obj['options']['font'] ?? 'helvetica',
            "fontsize" => $obj['options']['fontsize'] ?? 8,
            "stretchtext" => $obj['options']['stretchtext'] ?? 4,
        ];

        // process colors
        if ($style['fgcolor'][0] === "#") {
            $style['fgcolor'] = $this->convertHexToRGBColor($style['fgcolor']);
        }

        if ($style['bgcolor'] !== false && $style['bgcolor'][0] === "#") {
            $style['bgcolor'] = $this->convertHexToRGBColor($style['bgcolor']);
        }

        // parse string content
        $content = (isset($obj['content'])) ? $this->parseStringData($obj['content'], $data) : "";
        $dataContent = (isset($obj['data'])) ? $this->getDataField($obj['data'], $data) : "";

        $barcodeContent = $content . $dataContent;

        // fix wrong EAN-13 text length to increase compatibility
        if (strlen($barcodeContent) < 12 && strtoupper($style['type']) === 'EAN13') {
            $barcodeContent = str_pad($barcodeContent, 12, '0', STR_PAD_LEFT);
        }

        // start rotation transform if rotation is not zero
        if ($options['rotation'] != 0) {
            $this->pdf->StartTransform();
            $this->pdf->Rotate($options['rotation'], $options['x'], $options['y']);
        }

        $this->pdf->write1DBarcode($barcodeContent, $style['type'], $options['x'], $options['y'], $options['width'], $options['height'], $options['xres'], $style, $options['align']);

        // stop rotation transform
        if ($options['rotation'] != 0) {
            $this->pdf->StopTransform();
        }
    }

    protected function renderQrCode($obj, $data = [])
    {
        // set image scale factor
        $this->pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // parse relative positioning
        // dx
        $defaultX = $this->pdf->getX();
        if (isset($obj['options']['dx'])) {
            $defaultX += $obj['options']['dx'];
        }

        // dy
        $defaultY = $this->pdf->getY();
        if (isset($obj['options']['dy'])) {
            $defaultY += $obj['options']['dy'];
        }

        // details relative X and Y
        if (isset($obj['options']['detailsX'])) {
            $defaultX = $this->detailsCurrentX + $obj['options']['detailsX'];
        }
        if (isset($obj['options']['detailsY'])) {
            $defaultY = $this->detailsCurrentY + $obj['options']['detailsY'];
        }

        // use stored X and Y positions
        if (isset($obj['options']['storedX'])) {
            $defaultX = $this->storedPositions[$obj['options']['storedX']]['x'];
        }
        if (isset($obj['options']['storedY'])) {
            $defaultY = $this->storedPositions[$obj['options']['storedY']]['y'];
        }

        // options
        $options = [
            "x" => $obj['options']['x'] ?? $defaultX,
            "y" => $obj['options']['y'] ?? $defaultY,
            "width" => $obj['options']['width'] ?? 10,
            "height" => $obj['options']['height'] ?? 10,
            "group-header" => $obj['options']['group-header'] ?? false,
        ];

        // check if group header can be printed
        if ($options['group-header'] === true && isset($this->printGroupHeader[$this->currentDetailsDataField]) && !$this->printGroupHeader[$this->currentDetailsDataField]) {
            return false;
        }

        // set style for barcode
        $style = array(
            'border' => $obj['options']['border'] ?? true,
            'vpadding' => $obj['options']['vpadding'] ?? 'auto',
            'hpadding' => $obj['options']['hpadding'] ?? 'auto',
            'fgcolor' => $obj['options']['fgcolor'] ?? array(0, 0, 0),
            'bgcolor' => $obj['options']['bgcolor'] ?? false, //array(255,255,255)
            'module_width' => $obj['options']['module_width'] ?? 1, // width of a single module in points
            'module_height' => $obj['options']['module_height'] ?? 1 // height of a single module in points
        );

        // parse string content
        $content = (isset($obj['content'])) ? $this->parseStringData($obj['content'], $data) : "";
        $dataContent = (isset($obj['data'])) ? $this->getDataField($obj['data'], $data) : "";

        // set style for barcode
        $this->pdf->write2DBarcode($content . $dataContent, 'QRCODE,H', $options['x'], $options['y'], $options['width'], $options['height'], $style);
    }

    protected function newLine($obj = [], $newLineSpace = 4)
    {
        $options = [
            "group-header" => $obj['options']['group-header'] ?? false,
            "height" => $obj['options']['height'] ?? $newLineSpace,
        ];

        // check if group header can be printed
        if ($options['group-header'] === true && isset($this->printGroupHeader[$this->currentDetailsDataField]) && !$this->printGroupHeader[$this->currentDetailsDataField]) {
            return false;
        }

        $this->pdf->Ln($options['height']);

        return true;
    }

    protected function setFont($obj)
    {
        $options = [
            "font-family" => $obj['font-family'] ?? $this->lastFont,
            "font-decoration" => $obj['font-decoration'] ?? "",
            "font-size" => $obj['font-size'] ?? 12
        ];

        // save last used font and font size
        $this->lastFont = $options['font-family'];
        $this->lastFontSize = $options["font-size"];

        // set last font
        $this->pdf->SetFont($options['font-family'], $options['font-decoration'], $options['font-size']);
    }

    protected function renderHeaders($obj)
    {
        foreach ($obj['children'] as $headerComponent) {
            $this->renderComponent($headerComponent);
        }
    }

    protected function currentDetailsMaxY($dataTable)
    {

        // current coordinates
        $currentX = $this->pdf->getX();
        $currentY = $this->pdf->getY();

        // check if current details has already registered max Y position
        $maxDetailsY = (isset($this->maxDetailsY[$dataTable])) ? $this->maxDetailsY[$dataTable] : $currentY;

        // check max Y adding a line break in order to move the caret to the end of the last rendered element
        $this->newLine([], 0);
        $newY = $this->pdf->getY();
        $maxDetailsY = ($newY > $maxDetailsY) ? $newY : $maxDetailsY;

        // store current Y max position
        $this->maxDetailsY[$dataTable] = $maxDetailsY;

        // restore current coordinates
        $this->pdf->setXY($currentX, $currentY);

        return $maxDetailsY;
    }

    protected function renderDetails($obj)
    {
        /* check if data is already being processed */
        if (!empty($this->details[$obj['data']])) {
            $data = $this->details[$obj['data']];
        } else {
            /* first time rendering data */
            $data = $this->getDataField($obj['data']);
            if (!empty($data)) {

                /* check if current details isnt saved */
                if (!isset($this->data[$this->currentDetailsDataField][0][$obj['data']])) {
                    $this->details[$obj['data']] = $data;
                }
            }
        }

        // set current details data field
        if (!empty($data) && !isset($this->data[$this->currentDetailsDataField][0][$obj['data']])) {

            // store current details table before updating it in order to use it as parent
            if ($this->previousDetailsDataField !== $obj['data']) {
                $this->previousDetailsDataField = $this->currentDetailsDataField;
            }

            // set new current data table to be iterated
            $this->currentDetailsDataField = $obj['data'];
        }

        /* object default options */
        $options = [
            "height" => $obj['options']['height'] ?? 100,
            "row-height" => $obj['options']['row-height'] ?? null,
            "row-condition" => $obj['options']['row-condition'] ?? null,
            "parent-join-column" => $obj['options']['parent-join-column'] ?? null,
            "table-join-column" => $obj['options']['table-join-column'] ?? null,
            "margin" => $obj['options']['margin'] ?? 4,
            "x" => $obj['options']['x'] ?? $this->pdf->getX(),
            "y" => $obj['options']['y'] ?? $this->pdf->getY(),
            "overflow-margin" => $obj['options']['overflow-margin'] ?? 6,
            "group-by" => $obj['options']['group-by'] ?? false,
            "line-break" => $obj['options']['line-break'] ?? true,
        ];

        // set Y position
        if ($options['y'] > 0) {
            $this->pdf->setY($options['y']);
        }
        // set X position
        if ($options['x'] > 0) {
            $this->pdf->setX($options['x']);
        }

        $startY = $this->pdf->getY();
        $endY = $startY + $options['height'];

        // group by
        if ($options['group-by'] !== false && !empty($data) && !isset($this->lastGroupHeaders[$obj['data']])) {

            // store group field name
            $groupField = $options['group-by'];

            // sort array
            usort($data, function ($a, $b) use ($groupField) {
                return strcmp($a[$groupField], $b[$groupField]);
            });

            // store changed data to the sorted one
            if (!empty($data)) $this->details[$obj['data']] = $data;

            // initialize group headers
            $this->lastGroupHeaders[$obj['data']] = [];
        }

        // render each line
        if (!empty($data)) {

            $this->maxDetailsY[$obj['data']] = 0;

            foreach ($data as $detail) {

                /* check details row visibility */
                if ($options['row-condition'] !== null) {
                    if (!($this->checkVisibleCondition($obj, $detail, 'row-condition'))) {

                        // remove the first data from the details array
                        array_shift($this->details[$obj['data']]);

                        // clear data property
                        if (empty($this->details[$obj['data']])) unset($this->details[$obj['data']]);

                        continue;
                    }
                } else if ($options['parent-join-column'] !== null && $options['table-join-column'] !== null) {
                    if ($this->checkParentJoinCondition($options['parent-join-column'], $options['table-join-column'])) {

                        // remove the first data from the details array
                        array_shift($this->details[$obj['data']]);

                        // clear data property
                        if (empty($this->details[$obj['data']])) unset($this->details[$obj['data']]);

                        continue;
                    }
                }

                // check if header already has already been rendered
                if ($options['group-by'] && ((!isset($this->lastGroupHeaders[$obj['data']][$options['group-by']])) || (isset($this->lastGroupHeaders[$obj['data']][$options['group-by']]) && $this->lastGroupHeaders[$obj['data']][$options['group-by']] !== $detail[$options['group-by']]))) {
                    $this->lastGroupHeaders[$obj['data']][$options['group-by']] = $detail[$options['group-by']];
                    $this->printGroupHeader[$obj['data']] = true; // tell children with group-header to be processed
                } elseif ($options['group-by'] && isset($this->lastGroupHeaders[$obj['data']][$options['group-by']])) {
                    $this->printGroupHeader[$obj['data']] = false; // avoid group-header to components to be processed
                }

                $this->detailsCurrentX = $this->pdf->getX();
                $this->detailsCurrentY = $this->currentDetailsMaxY($obj['data']);
                $this->currentDetailsBaseY = $this->pdf->getY();

                /* set rendering save point in order to restore it if necessary below */
                if ($this->transactionStartedComponent === null) {
                    $this->pdf->startTransaction();
                    $this->transactionStartedComponent = $obj['data'];
                }

                // iterate componentes in each row
                foreach ($obj['children'] as $childrenComponent) {
                    // render child component
                    $this->renderComponent($childrenComponent, $detail);

                    /* update maximum Y */
                    $maxDetailsY = $this->currentDetailsMaxY($obj['data']);
                }

                /* store current X position after component rendering */
                $afterComponentX = $this->pdf->getX();

                // set X position again
                // in FPDF X only can be set after Y, Y default X to 10
                if ($options['x'] > 0 && $options['line-break']) {
                    $this->pdf->setX($options['x']);
                } else {
                    /* if no line break exists applys stored X position */
                    $this->pdf->setX($afterComponentX);
                }

                // render new line
                if ($options['line-break']) {
                    $this->newLine([], $options['margin']);
                }

                if ($options['row-height'] !== null) {
                    $this->pdf->setY($maxDetailsY + $options['row-height']);
                } else {
                    $this->pdf->setY($maxDetailsY);
                }

                /* check if data is available */
                if (isset($this->details[$obj['data']])) {
                    /* store rendered componentes row data in order to be restored if necessary */
                    $renderedRowData = $this->details[$obj['data']][0];

                    // remove the first data from the details array
                    array_shift($this->details[$obj['data']]);
                }

                // clear data property
                if (empty($this->details[$obj['data']])) unset($this->details[$obj['data']]);

                // get current Y position
                $posY = $this->pdf->getY();

                // check Y position render limit
                if ($posY >= ($endY - $options['overflow-margin'])) {

                    // check if group header was printed on this iteration and reset it
                    if ($this->printGroupHeader[$obj['data']] = true) {
                        unset($this->lastGroupHeaders[$obj['data']][$options['group-by']]);
                    }

                    // rollback last rendering
                    $this->pdf = $this->pdf->rollbackTransaction();

                    /* restore rendered row data in order to be rendered again on the next page */
                    if (isset($this->details[$obj['data']])) {
                        array_unshift($this->details[$obj['data']], $renderedRowData);
                    } elseif (isset($renderedRowData)) {
//                        if it's the last line it needs to recreate the data array in order to render it on the next page
                        $this->details[$obj['data']] = [];
                        $this->details[$obj['data']][] = $renderedRowData;
                    }

                    /* returns nothing to prevent further rendering of the details on the current page */
                    return;
                }

                /* if the current PDF transaction was started by this component it ends it */
                if ($this->transactionStartedComponent === $obj['data']) {
                    $this->transactionStartedComponent = null;
                }
            }
        }
    }

    /**
     * Renders the footers of a component.
     *
     * @param array $obj The component data with children attribute.
     * @return void
     */
    protected function renderFooters($obj)
    {
        foreach ($obj['children'] as $footerComponent) {
            $this->renderComponent($footerComponent);
        }
    }

    protected function renderGroups($obj)
    {
        foreach ($obj['children'] as $groupComponent) {
            $this->renderComponent($groupComponent);
        }
    }

    protected function renderStorePosition($obj)
    {
        /* object default options */
        $options = [
            "name" => $obj['options']['name'] ?? 'default'
        ];

        $this->storedPositions[$options['name']] = [
            "x" => $this->pdf->getX(),
            "y" => $this->pdf->getY(),
        ];
    }

    /**
     * Checks the parent join condition between two columns.
     *
     * @param string $parentJoinColumn The column in the parent details data.
     * @param string $objectJoinColumn The column in the current details data.
     *
     * @return bool Returns true if the parent join condition is satisfied, false otherwise.
     */
    protected function checkParentJoinCondition($parentJoinColumn, $objectJoinColumn)
    {
        return ($this->data[$this->previousDetailsDataField][0][$parentJoinColumn] == $this->data[$this->currentDetailsDataField][0][$objectJoinColumn]);
    }

    protected function checkVisibleCondition($tObj, $data = [], $optionName = 'show-if')
    {
        $visible = true;

        if (isset($tObj[$optionName]) || isset($tObj['options'][$optionName])) {
            $condition = (isset($tObj['options'][$optionName])) ? $tObj['options'][$optionName] : $tObj[$optionName];

            // extract condition type from the IF property
            $regexComparer = '/(.+)(>=|<=|==|<|>)(.+)/';
            preg_match_all($regexComparer, $condition, $matches, PREG_SET_ORDER);

            $dataField = $this->getDataField($condition, $data);

            /* check data */
            $noData = true;
            if (is_string($dataField)) {
                $noData = (strlen($dataField) === 0);
            } else if (is_array($dataField)) {
                $noData = (empty($dataField));
            }

            /* set visibility condition */
            if (!$this->designMode && empty($matches) && $noData) {
                // no comparer condition
                $visible = false;
            } else {
                if (!empty($matches)) {
                    // condition with comparison
                    $dataFields = trim($matches[0][1]);
                    $comparer = trim($matches[0][2]);

                    // trim and remove quotes from the value
                    $value = trim(str_replace("'", "", $matches[0][3]));

                    // check if other value is variable
                    if (!is_numeric($value) && strpos($value, ".") !== false) {
                        $value = $this->getDataField($value, $data);
                    }

                    $dataValue = ($this->getDataField($dataFields, $data) . $this->parseGlobalVariables($dataFields, false, true));

                    switch ($comparer) {
                        case '>':
                            return ($dataValue > $value);
                        case '<':
                            return ($dataValue < $value);
                        case '>=':
                            return ($dataValue >= $value);
                        case '<=':
                            return ($dataValue <= $value);
                        case '==':
                            return ($dataValue == $value);
                    }
                }
            }
        }

        return $visible;
    }

    /**
     * render template variables
     * @param $tObj
     * @return void
     */
    protected function renderTemplateVariables($tObj)
    {
        if (isset($tObj['data'])) {
            $this->templateVariables = $tObj['data'];
        }
    }

    /**
     * Parse global options components variables using [[VARIABLE]] syntax
     * @param $tObj
     * @return mixed
     */
    protected function parseOptionsGlobalVariables($tObj)
    {

        $globalVariables = [];
        $children = [];

        if (isset($tObj['children'])) {
            $children = $tObj['children'];
            unset($tObj['children']);
        }

        /* fill details max Y positions */
        foreach ($this->maxDetailsY as $i => $v) {
            $globalVariables["max_y_$i"] = $v;
        }

        /* details X and Y*/
        $globalVariables["details_x"] = $this->detailsCurrentX ?? 0;
        $globalVariables["details_y"] = $this->detailsCurrentY ?? 0;

        /* convert to JSON string in order to replace the values easily */
        $tObjJSON = json_encode($tObj);

        /*echo "maxY :: ".$this->pdf->getY();*/
        /*echo $tObjJSON."<br><br>";*/

        /* parse global variables */
        foreach ($globalVariables as $name => $value) {
            $tObjJSON = str_replace("[[$name]]", $value, $tObjJSON);
        }

        // convert JSON string back to object
        $tObj = json_decode($tObjJSON, true);

        // restore children
        if (!empty($children)) {
            $tObj['children'] = $children;
        }

        /* returns processed array */
        return $tObj;
    }

    protected function renderComponent($tObj, $data = [], $dataName = "")
    {
        // parse object global variables
        $tObj = $this->parseOptionsGlobalVariables($tObj);

        // visibility conditions
        // ELSE
        if (isset($tObj['else']) && $this->lastShowIf === true) {
            $this->lastShowIf = null;
            return;
        }

        // SHOW-IF
        if (isset($tObj['show-if'])) {
            if (!$this->checkVisibleCondition($tObj, $data)) {
                $this->lastShowIf = false;
                return;
            } else {
                $this->lastShowIf = true;
            }
        } else {
            $this->lastShowIf = null;
        }

        // render only on last or first page
        if (isset($tObj['first_page']) && $tObj['first_page'] === true && $this->pageCount > 1) return;
        if (isset($tObj['not_first_page']) && $tObj['not_first_page'] === true && $this->pageCount == 1) return;
        if (isset($tObj['last_page']) && $tObj['last_page'] === true && !$this->isLastPage()) return;
        if (isset($tObj['not_last_page']) && $tObj['not_last_page'] === true && $this->isLastPage()) return;

        $type = (isset($tObj['type'])) ? $tObj['type'] : "text";

        switch ($type) {
            case 'page':
                $this->renderPage($tObj);
                break;
            case 'data':
                $this->renderTemplateVariables($tObj);
                break;
            case 'text':
                $this->renderText($tObj, $data);
                break;
            case 'cell':
                $this->renderCell($tObj, $data);
                break;
            case 'image':
                $this->renderImage($tObj, $data);
                break;
            case 'box':
                $this->renderBox($tObj);
                break;
            case 'ellipse':
                $this->renderEllipse($tObj);
                break;
            case 'line':
                $this->renderLine($tObj);
                break;
            case 'barcode':
                $this->render1DBarcode($tObj, $data);
                break;
            case 'qrcode':
                $this->renderQRCode($tObj, $data);
                break;
            case 'break':
                $this->newLine($tObj);
                break;
            case 'font':
                $this->setFont($tObj);
                break;
            case 'header':
                $this->renderHeaders($tObj);
                break;
            case 'details':
                if (!$this->designMode) $this->renderDetails($tObj);
                break;
            case 'footer':
                $this->renderFooters($tObj);
                break;
            case 'group':
                $this->renderGroups($tObj);
                break;
            case 'store-position':
                $this->renderStorePosition($tObj);
                break;
        }
    }

    protected function processTemplate()
    {
        $templateObject = json_decode($this->jsonTemplate, true);

        // document copies iteration
        for ($this->currentDocumentCopy = 1; $this->currentDocumentCopy <= $this->documentCopies; $this->currentDocumentCopy++) {

            // page counter
            $this->pageCount = 0;

            // clear all data arrays on each new copy
            unset($this->printGroupHeader);
            unset($this->lastGroupHeaders);
            unset($this->currentDetailsDataField);

            // render components checking if there are any details remaining
            do {
                foreach ($templateObject as $tObj) {
                    $this->renderComponent($tObj);
                }
            } while (!empty($this->details));
        }
    }

    public function render()
    {
        $this->processTemplate();
        $this->pdf->Output($this->fileName, $this->outputType);

        return true;
    }
}
