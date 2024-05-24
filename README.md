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
  "person-city" => "Liverpool",
  "lines" => [
    ["barcode" => 1, "price" => 2],
    ["barcode" => 2, "price" => 3.45]
  ]
]);

// render PDF file
$pdfParser->render();
```

### Data
Data to be used as variables on the template.
```javascript
{
  "type": "data",
  "data": {
    "field1": "Value of field 1",
    "field2" : "Value of field 2"
  }
}
```
Data can be used on `text` strings using the following format: `[[field1]]` for example.


### Page
Page is the only required component, and must always be placed at the beginning of the JSON structure.
```javascript
{
  "type": "page",
  "options": {
    "format": "A4" // The values can be A4,A5,... and also [sizeX,sizeY],
    "units": "mm",
    "orientation": "P" // "P" for portrait or "L" for landscape,
    "topMargin": 10,
    "leftMargin": 10,
    "rightMargin": 10,
    "keepMargins": true, // overwrite default margins on all pages
    "autoPageBreak": true,
    "encoding": "UTF-8",
    "printHeader": false,
    "printFooter": false
  }
}
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
  "data": "lines", // variable name to be iterated
  "options":{
    "x": 10,
    "y": 50,
    "margin": 4, // margin between each line
    "height": 200, // section height per page
    "row-height": 50,  // static row height
    "row-condition": "price > 0", // condition for row visibility using details data
    "parent-join-column": "parent_data_column_name" // name of the parent column data to compare
    "table-join-column": "data_column_name" // name of the current details column to join by with the parent column
    "overflow-margin": 6, // when data aproaches this end margin generates new page
    "group-by": "field_name", // group details using the specified field name
    "line-break": true // if a line break should pe placed on each row end
  },
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
#### Text
```javascript
{
  "type": "text",
  "text": "Hello world! %d {{variable}}" // string to be shown, can be used %d for the data value
  "data": "person_name" // data array index
  "options": {
    "x": 10,
    "y": 10,
    "dx": 10, // relative X position to the current X
    "dy": 10, // relative Y position to the current Y
    "detailsX": 10, // details row relative X
    "detailsY": 10, // details row relative Y
    "font-size": 12,
    "font-family": "times",
    "color": "#ff00ff",
    "bg-color": "#0000ff",
    "text-decoration": "B", // B-Bold, I-Italic, empty-none, can be used together
    "rotation": 0,
    "utf8": false, // force UTF-8 encoding
    "html_decoding": true, // convert HTML entities to chars,
    "group-header": false // only render on each details group header
  }
}
```

#### Cell
```javascript
{
  "type": "cell",
  "text": "Hello world! %d {{variable}}" // string to be shown, can be used %d for the data value
  "data": "person_name" // data array index
  "options": {
    "x": 10,
    "y": 10,
    "dx": 10, // relative X position to the current X
    "dy": 10, // relative Y position to the current Y
    "detailsX": 10, // details row relative X
    "detailsY": 10, // details row relative Y
    "width": 50,
    "height": 5,
    "border": 0, // 0-disable, 1-enable
    "multiline": false,
    "multiline-break": 0 // 0 - continues on the right, 1 - new line and beginning of the page, 2 - below
    "font-size": 12,
    "font-family": "times",
    "color": "#ff00ff",
    "bg-color": "#0000ff",
    "text-align": "L" // L-left, R-right, C-center, J-justified
    "text-decoration": "B", // B-Bold, I-Italic, empty-none, can be used together
    "rotation": 0 // degrees,
    "utf8": false, // force UTF-8 encoding
    "html_decoding": true, // convert HTML entities to chars
    "group-header": false, // only render on each details group header
    "auto-width": false // set the cell width to the content
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
    "dx": 10, // relative X position to the current X
    "dy": 10, // relative Y position to the current Y
    "detailsX": 10, // details row relative X
    "detailsY": 10, // details row relative Y
    "width": 50,
    "height": 5,
    "border": 0,
    "dpi": 300,
    "resize": false,
    "link": "",
    "align": "",
    "palign": "",
    "fitbox": true, // auto image resize
    "group-header": false, // only render on each details group header,
    "use-cache": true, // control image cache in order to force refresh
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
    "color" => [0,0,0], // RGB array
    "group-header": false // only render on each details group header
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
    "dx": 10, // relative X position to the current X
    "dy": 10, // relative Y position to the current Y
    "detailsX": 10, // details row relative X
    "detailsY": 10, // details row relative Y
    "width": 50,
    "height": 50,
    "border-width": 0.1,
    "border-color": [0,0,0],
    "fill-color": [255,255,255],
    "group-header": false // only render on each details group header
  }
}
```


#### Ellipses / Circles
```javascript
{
  "type": "ellipse",
  "options": {
    "x": 10,
    "y": 10,
    "dx": 10, // relative X position to the current X
    "dy": 10, // relative Y position to the current Y
    "detailsX": 10, // details row relative X
    "detailsY": 10, // details row relative Y
    "width": 50,
    "height": 50,
    "border-width": 0.1,
    "border-color": [0,0,0],
    "fill-color": [255,255,255],
    "group-header": false // only render on each details group header
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
    "dx": 10, // relative X position to the current X
    "dy": 10, // relative Y position to the current Y
    "detailsX": 10, // details row relative X
    "detailsY": 10, // details row relative Y
    "width": 50,
    "height": 10,
    "xres": 0.4,
    "align": "N",
    "group-header": false // only render on each details group header
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
  "type": "qrcode",
  "content": "www.yoursite.com",
  "data": "variable.field" // data array variable
  "options": {
    "x": 10,
    "y": 10,
    "dx": 10, // relative X position to the current X
    "dy": 10, // relative Y position to the current Y
    "detailsX": 10, // details row relative X
    "detailsY": 10, // details row relative Y
    "width": 50,
    "height": 50,
    "group-header": false, // only render on each details group header
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

#### Group
The group component will apply conditions to children elements
```javascript
{
  "type": "group",
  "children": [
    // group components
  ]
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
  "type": "break",
  "options" :{
    "group-header": false,
    "height": 4
  }
}
```


#### Store current position
```javascript
{
  "type": "store-position",
  "options" :{
    "name": "store-name" 
  }
}
```
Using this component the current **X and Y** are stored with the given name and can be accessed using the `storedX` and `storedY` options in the components.
```javascript
// Usage example
{
  "type": "cell",
  "options": {
    "storedX": "store-name",
    "storedY": "store-name"
  }
}
```

### Global variables
```javascript
{
  "text": "{{current_date}}" // Current date: format YYYY-MM-DD
  "text": "{{current_year}}" // Current year: format YYYY
  "text": "{{current_month}}" // Current month: format MM
  "text": "{{current_day}}" // Current day of the month: format DD
  "text": "{{current_time}}" // Current time: format HH:mm:ss
  "text": "{{page_number}}" // Current page number
  "text": "{{total_pages}}" // Total document pages
  "text": "{{current_copy}}" // Current document copy
  "text": "{{document_copies}}" // Total number of document copies to be generated
   "text":  "[[max_y_DETAILSDATATABLE]]" //Global variable to obtain the actual selected table, DETAILSDATATABLE must be replaced by the details section data field value.

}
```


### Conditional Properties
Show only on first page.
```javascript
{
  "first_page": true, // show only on first page
  "not_first_page": true, // show on all pages except first one
  "last_page": true, // show only on the last page
  "not_last_page": true, // not show on the last page
}
```

#### SHOW-IF
```javascript
{
"show-if": "item.type=='ITEM'" // check if variable has value
"show-if": "company.phone_number" // check if variable has value
"show-if": "item.price > 5" // compare values
"show-if": "item.quantity <= options.required_minimum_purchase" // its possible to compare variables also
}
```

#### ELSE
```javascript
{
"else": true // if the previous component is false this component will be shown instead
}
```
