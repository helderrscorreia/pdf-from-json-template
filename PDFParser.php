<?php
/**
 * Class PDFParser
 * @description Transform a JSON object defined structure into PDF document.
 * @author Hélder Correia
 *
 * https://www.goldylocks.pt
 * Goldylocks Portugal
 */

class PDFParser
{
    private string $jsonTemplate;
    private $pdf;
    private $fileName;
    private $outputType = "I";
    private $data = [];
    private $details = [];
    private $pageCount = 0;
    private $documentCopies = 1;
    private $currentDocumentCopy = 1;

    public function __construct($jsonTemplate, $fileName = "document.pdf")
    {
        $this->jsonTemplate = $jsonTemplate;
        $this->fileName = $fileName;
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
     * parse data in strings
     * @param $string
     * @param array $dataArray
     * @return array|string|string[]|null
     */
    public function parseStringData($string, $dataArray = [])
    {
        // page numbers
        $string = str_replace("{{page_number}}", $this->pdf->getAliasNumPage(), $string);
        $string = str_replace("{{total_pages}}", $this->pdf->getAliasNbPages(), $string);

        // global variables
        $string = preg_replace_callback('/{{([\w\.]+)}}/', function ($match) {
            return $this->getDataField($match[1], $this->data) ?? "";
        }, $string);

        // details variables
        return preg_replace_callback('/{{([\w\.]+)}}/', function ($match) use ($dataArray) {
            return $this->getDataField($match[1], $dataArray);
        }, $string);
    }

    /**
     * get data field value
     * @param $fieldPath
     * @return array|mixed
     */
    public function getDataField($fieldPath, $dataArray = [])
    {
        $explodedPath = explode('.', $fieldPath);

        if (!empty($dataArray)) {
            // parameter data
            $data = $dataArray;
        } else {
            // global data
            $data = $this->data;
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
            $this->pdf->setPrintHeader($pageSetup['printHeader']);
            $this->pdf->setPrintFooter($pageSetup['printFooter']);
            $this->pdf->setAutoPageBreak($pageSetup['autoPageBreak']);
        }

        // add new page
        $this->pdf->AddPage();

        // increment page count
        $this->pageCount++;
    }

    /**
     * render text element
     * @param $obj
     */
    protected function renderText($obj, $dataArray = [])
    {
        $textOptions = [
            "x" => $obj['options']['x'] ?? 0,
            "y" => $obj['options']['y'] ?? 0,
            "color" => $obj['options']['color'] ?? [0, 0, 0],
            "font-size" => $obj['options']['font-size'] ?? 12,
            "font-family" => $obj['options']['font-family'] ?? 'times',
            "text-decoration" => $obj['options']['text-decoration'] ?? '',
            "rotation" => $obj['options']['rotation'] ?? 0
        ];

        // process color
        if ($textOptions['color'][0] === "#") {
            $textOptions['color'] = $this->convertHexToRGBColor($textOptions['color']);
        }

        // text parse
        $data = isset($obj['data']) ? $this->getDataField($obj['data'], $dataArray) : "";
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

        $this->pdf->StartTransform();
        $this->pdf->Rotate($textOptions['rotation']);
        $this->pdf->Text($posX, $posY, $text . $data);
        $this->pdf->StopTransform();

    }

    protected function renderCell($obj, $dataArray = [])
    {
        $cellOptions = [
            "x" => $obj['options']['x'] ?? 0,
            "y" => $obj['options']['y'] ?? 0,
            "width" => $obj['options']['width'] ?? 50,
            "height" => $obj['options']['height'] ?? 5,
            "color" => $obj['options']['color'] ?? [0, 0, 0],
            "font-size" => $obj['options']['font-size'] ?? 12,
            "font-family" => $obj['options']['font-family'] ?? 'times',
            "text-decoration" => $obj['options']['text-decoration'] ?? '',
            "text-align" => $obj['options']['text-align'] ?? 'L',
            "border" => $obj['options']['border'] ?? '',
            "multiline" => $obj['options']['multiline'] ?? false,
            "rotation" => $obj['options']['rotation'] ?? 0
        ];

        // process color
        if ($cellOptions['color'][0] === "#") {
            $cellOptions['color'] = $this->convertHexToRGBColor($cellOptions['color']);
        }

        // text or data
        $data = isset($obj['data']) ? $this->getDataField($obj['data'], $dataArray) : "";
        $text = isset($obj['text']) ? $this->parseStringData($obj['text'], $dataArray) : "";

        // set font parameters
        $this->pdf->SetFont($cellOptions['font-family'], $cellOptions['text-decoration'], $cellOptions['font-size']);
        $this->pdf->SetTextColor($cellOptions['color'][0], $cellOptions['color'][1], $cellOptions['color'][2]);

        // set XY position
        if ($cellOptions['y'] !== 0) {
            $this->pdf->SetY($cellOptions['y']);
        }
        if ($cellOptions['x'] !== 0) {
            $this->pdf->SetX($cellOptions['x']);
        }

        // render cell
        if ($cellOptions['multiline'] === true) {
            $this->pdf->MultiCell($cellOptions['width'], $cellOptions['height'], $text . $data, $cellOptions['border'], $cellOptions['text-align']);
        } else {
            $this->pdf->StartTransform();
            $this->pdf->Rotate($cellOptions['rotation']);
            $this->pdf->Cell($cellOptions['width'], $cellOptions['height'], $text . $data, $cellOptions['border'], $cellOptions['text-align']);
            $this->pdf->StopTransform();
        }
    }

    protected function renderImage($obj)
    {
        $imageOptions = [
            "x" => $obj['options']['x'] ?? 0,
            "y" => $obj['options']['y'] ?? 0,
            "width" => $obj['options']['width'] ?? 5,
            "height" => $obj['options']['height'] ?? 40
        ];

        $this->pdf->Image($obj['src'], $imageOptions['x'], $imageOptions['y'], $imageOptions['width'], $imageOptions['height']);
    }

    protected function renderBox($obj)
    {
        $boxOptions = [
            "x" => $obj['options']['x'] ?? 0,
            "y" => $obj['options']['y'] ?? 0,
            "width" => $obj['options']['width'] ?? 0,
            "height" => $obj['options']['height'] ?? 0,
            "border-width" => $obj['options']['border-width'] ?? 0.1,
            "border-color" => $obj['options']['border-color'] ?? [0, 0, 0],
            "fill-color" => $obj['options']['fill-color'] ?? [255, 255, 255]
        ];

        $this->pdf->SetLineWidth($boxOptions['border-width']);
        $this->pdf->SetDrawColor($boxOptions['border-color'][0], $boxOptions['border-color'][1], $boxOptions['border-color'][2]);
        $this->pdf->SetFillColor($boxOptions['fill-color'][0], $boxOptions['fill-color'][1], $boxOptions['fill-color'][2]);

        $this->pdf->Rect($boxOptions['x'], $boxOptions['y'], $boxOptions['width'], $boxOptions['height'], 'DF');
    }

    protected function renderLine($obj)
    {
        $lineOptions = [
            "x1" => $obj['options']['x1'] ?? 0,
            "y1" => $obj['options']['y1'] ?? 0,
            "x2" => $obj['options']['x2'] ?? 0,
            "y2" => $obj['options']['y2'] ?? 0,
            "width" => $obj['options']['width'] ?? 0.1,
            "color" => $obj['options']['color'] ?? [0, 0, 0]
        ];

        $this->pdf->SetLineWidth($lineOptions['width']);
        $this->pdf->SetDrawColor($lineOptions['color'][0], $lineOptions['color'][1], $lineOptions['color'][2]);

        $this->pdf->Line($lineOptions['x1'], $lineOptions['y1'], $lineOptions['x2'], $lineOptions['y2']);
    }

    protected function renderQrCode($obj, $data = [])
    {
        // set image scale factor
        $this->pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // options
        $options = [
            "x" => $obj['options']['x'] ?? 0,
            "y" => $obj['options']['y'] ?? 0,
            "width" => $obj['options']['width'] ?? 10,
            "height" => $obj['options']['height'] ?? 10
        ];

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

    protected function newLine($newLineSpace = "")
    {
        $this->pdf->Ln($newLineSpace);
    }

    protected function renderHeaders($obj)
    {
        foreach ($obj['children'] as $headerComponent) {
            $this->renderComponent($headerComponent);
        }
    }

    protected function renderDetails($obj)
    {
        if (!empty($this->details)) {
            $data = $this->details;
        } else {
            $data = $this->getDataField($obj['data']);
            $this->details = $data;
        }

        $options = [
            "height" => $obj['options']['height'] ?? 100,
            "width" => $obj['options']['width'] ?? 100,
            "x" => $obj['options']['x'] ?? 0,
            "y" => $obj['options']['y'] ?? 0,
            "overflow-margin" => $obj['options']['overflow-margin'] ?? 6,
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

        // render each line
        foreach ($data as $detail) {
            foreach ($obj['children'] as $childrenComponent) {
                $this->renderComponent($childrenComponent, $detail);
            }

            // render new line
            $this->newLine();

            // set X position again
            // in FPDF X only can be set after Y, Y default X to 10
            if ($options['x'] > 0) {
                $this->pdf->setX($options['x']);
            }

            // remove the first data from the details array
            array_shift($this->details);

            // get current Y position
            $posY = $this->pdf->getY();

            // check Y position render limit
            if ($posY >= ($endY - $options['overflow-margin'])) {
                return;
            }
        }
    }

    protected function renderFooters($obj)
    {
        foreach ($obj['children'] as $footerComponent) {
            $this->renderComponent($footerComponent);
        }
    }

    protected function renderComponent($tObj, $data = [])
    {

        // visibility conditions
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
            case 'text':
                $this->renderText($tObj, $data);
                break;
            case 'cell':
                $this->renderCell($tObj, $data);
                break;
            case 'image':
                $this->renderImage($tObj);
                break;
            case 'box':
                $this->renderBox($tObj);
                break;
            case 'line':
                $this->renderLine($tObj);
                break;
            case 'qrcode':
                $this->renderQRCode($tObj, $data);
                break;
            case 'header':
                $this->renderHeaders($tObj);
                break;
            case 'details':
                $this->renderDetails($tObj);
                break;
            case 'footer':
                $this->renderFooters($tObj);
                break;
        }
    }

    protected function processTemplate()
    {
        $templateObject = json_decode($this->jsonTemplate, true);

        // document copies iteration
        for ($this->currentDocumentCopy = 1; $this->currentDocumentCopy <= $this->documentCopies; $this->currentDocumentCopy++) {

            $this->pageCount = 0;

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
    }
}
