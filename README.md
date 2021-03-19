# pdf-from-json-template
Generate PDF documents from a JSON defined structure


### Requirements
To use this library you must have TCPDF included in your PHP project:
[https://github.com/tecnickcom/TCPDF](https://github.com/tecnickcom/TCPDF)

```php
// include TCPDF
include_once('./TCPDF_main/tcpdf_import.php');
```


### Setup
```php
include_once('PDFParser.php');
```

### Usage
```php
// load JSON template from file or variable
$JSONTemplateString = file_get_contents('samples/simple-a4.json');

// filename to be generated
$outputFilename = "document.pdf";

// instantiate PDFParser
$pdfParser = new PDFParser($JSONTemplateString, $outputFilename);

// set data array to be used
$pdfParser->setData([
  "person-name" => "John Silver",
  "person-address" => "Yellow Street 34",
  "person-zipcode" => "34500",
  "person-city" => "Liverpool"
]);

// render PDF file
$pdfParser->render();
```
