# Aurora PHP DTD Parser
This is a DTD parser contained a single PHP file that aims to be compliant to the full XML specification. The traditional method of reading XML files in PHP is to use the built-in functions based on libxml. However, these do not allow you to read and parse Document Type Definition (DTD) files, they only tell you if the DTD is not legal.

With this library, you can parse a DTD file and extract from it general and parameter entities, notations, element definitions and their attribute definitions and also processing instructions.

## Examples
### Print all declared entities
```
<?php
$dtd = \Aurora\DTD::parseText(file_get_contents("mydtd.dtd"));
foreach($dtd->generalEntities as $entity)
{
 echo $entity->Name . ": " . $entity->replacementText . "\n";
}
foreach($dtd->parameterEntities as $entity)
{
 echo $entity->Name . ": " . $entity->replacementText . "\n";
}
```