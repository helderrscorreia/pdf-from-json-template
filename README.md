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

// OPTIONAL: set data array to be used
$pdfParser->setData([
  "person-name" => "John Silver",
  "person-address" => "Yellow Street 34",
  "person-zipcode" => "34500",
  "person-city" => "Liverpool"
]);

// render PDF file
$pdfParser->render();
```

### Sections
#### Header
The header component will repeat itself at the beginning of new page.
```javascript
{
  "type": "header",
  "children": [
    // header components
  ]
}
```

#### Details
The details component accepts a data property that will iterate until the section height it's reached.
After the height it's maxed a new page will be created in order to iterate the remaining data again.

```javascript
{
  "type": "details",
  "details": "document.lines", // variable name to be iterated
  "children": [
      {
        "type": "cell",
        "data": "barcode" // sub variable to be used in each row column
      },
      {
        "type": "cell",
        "data": "price"
      }
    ]
}
```
#### Footer
The footer component will repeat itself at the end of each page.
```javascript
{
  "type": "footer",
  "children": [
    // footer components
  ]
}
```

### Components
#### Page
Page is the only required component, and must always be placed at the beginning of the JSON structure.
```javascript
{
  "type": "page",
  "options": {
    "format": "A4" // The values can be A4,A5,... and also [sizeX,sizeY],
    "units": "mm",
    "orientation": "P" // "P" for portrait or "L" for landscape
  }
}
```
#### Text
```javascript
{
  "type": "text",
  "text": "Hello world!" // string to be shown
  "data": "person_name" // data array index
  "options": {
    "x": 10,
    "y": 10,
    "font-size": 12,
    "font-family": "times",
    "color": "#ff00ff",
    "bg-color": "#0000ff",
    "text-decoration": "B", // B-Bold, I-Italic, empty-none, can be used together
    "rotation": 0
  }
}
```

#### Cell
```javascript
{
  "type": "cell",
  "text": "Hello world!" // string to be shown
  "data": "person_name" // data array index
  "options": {
    "x": 10,
    "y": 10,
    "width": 50,
    "height": 5,
    "border": 0, // 0-disable, 1-enable
    "multiline": false,
    "font-size": 12,
    "font-family": "times",
    "color": "#ff00ff",
    "bg-color": "#0000ff",
    "text-align": "L" // L-left, R-right, C-center, J-justified
    "text-decoration": "B", // B-Bold, I-Italic, empty-none, can be used together
    "rotation": 0 // degrees
  }
}
```

#### Image
```javascript
{
  "type": "image",
  "src": "https://www.yoursite.com/image.png",
  "options": {
    "x": 10,
    "y": 10,
    "width": 50,
    "height": 5
  }
}
```


#### Line
```javascript
{
  "type": "line",
  "options": {
    "x1" => 10,
    "y1" => 10,
    "x2" => 40,
    "y2" => 10,
    "width" => 0.1 // line width
    "color" => [0,0,0] // RGB array
   }
}
```


#### Box
```javascript
{
  "type": "box",
  "options": {
    "x": 10,
    "y": 10,
    "width": 50,
    "height": 50,
    "border-width": 0.1,
    "border-color": [0,0,0],
    "fill-color": [255,255,255]
  }
}
```



#### Barcode
```javascript
{
  "type": "barcode",
  "content": "123456789012",
  "data": "variable.field" // data array variable
  "options": {
    "type": "EAN13",
    "x": 10,
    "y": 10,
    "width": 50,
    "height": 10,
    "xres": 0.4,
    "align": "N",
   "position": "",
   "textalign": "C",
   "stretch": false,
   "fitwidth": true,
   "cellfitalign": "",
   "border": true,
   "hpadding": "auto",
   "vpadding": "auto",
   "fgcolor": [0,0,0],
   "bgcolor": false,
   "text": true,
   "font": "helvetica",
   "fontsize": 8,
   "stretchtext": 4
  }
}
```



#### QRCode
```javascript
{
  "type": "box",
  "content": "www.yoursite.com",
  "data": "variable.field" // data array variable
  "options": {
    "x": 10,
    "y": 10,
    "width": 50,
    "border": true,
    "vpadding": "auto",
    "hpadding": "auto",
    "fgcolor": [0,0,0],
    "bgcolor": [255,255,255],
    "module_width": 1,
    "module_height": 1
  }
}
```

### Utilities


#### Set Font
```javascript
{
  "type": "font",
  "font-family": "courier", // TCPDF font name
  "font-decoration": "", // empty, B or I
  "font-size": 12
}
```


#### Line break
```javascript
{
  "type": "break"
}
```
