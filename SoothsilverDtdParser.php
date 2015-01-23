<?php
/**
 * @defgroup SoothsilverDtdParser Soothsilver DTD Parser
 * A standalone DTD parser for PHP.
 * @{
 */
/**
 * @namespace Soothsilver
 * Contains all classes belonging to the standalone DTD parser. This is a supernamespace based on the author's nickname.
 */
/**
 * @namespace Soothsilver::DtdParser
 * Contains all classes belonging to the standalone DTD parser.
 */
/**
 * @namespace Soothsilver::DtdParser::Internal
 * Contains classes used internally by the Soothsilver DTD Parser. These do not form part of its public API.
 */

namespace Soothsilver\DtdParser {

    /**
     * Represents all information extracted from a Document Type Declaration file, possibly combined with an internal subset.
     */
    class DTD
    {
        /**
         * List of element types sorted in declaration order. If an ATTLIST declaration preceded the ELEMENT declaration,
         * the position is determined by the ATTLIST declaration.  This is an associative array.
         * @var Element[]
         */
        public $elements = [];
        /**
         * List of declared parameter entities. This is an associative array.
         * @var ParameterEntity[]
         */
        public $parameterEntities = [];
        /**
         * List of declared general entities. This is an associative array.
         * @var GeneralEntity[]
         */
        public $generalEntities = [];
        /**
         * List of declared notations. This is an associative array.
         * @var Notation[]
         */
        public $notations = [];
        /**
         * List of errors in the DTD document. If this array is not empty, the document violates the XML specification.
         * Even if an error is generated, some information about the document may be available in the DTD object.
         * @var Error[]
         */
        public $errors = [];
        /**
         * List of warnings generated when parsing the document. A warning is generated in some cases when the XML
         * specification allows a processor to generate a warning.
         * @var Error[]
         */
        public $warnings = [];
        /**
         * List of processing instructions encountered while parsing the document. Processing instructions are not executed
         * in any way.
         * @var ProcessingInstruction[]
         */
        public $processingInstructions = [];

        /**
         * Returns a boolean representing the well-formedness and validity of the DTD.
         * @return bool True, if no errors were triggered during parsing; false otherwise.
         */
        public function isWellFormedAndValid()
        {
            return count($this->errors) === 0;
        }

        /**
         * Notice: Parsing external entities is a security problem. User should be given an option to enable or disable it.
         * Since the DTD Parser is now used only in the XMLCheck project where it is not desirable to load external entities,
         * this functionality is not programmed in.
         * @var bool Should external entities be parsed as well?
         */
        private $shouldLoadExternalEntities = false;
        /**
         * @var int The character position in the DTD document where the parser is at.
         */
        private $currentOffset = 0;
        /**
         * @var int The line in the DTD document that is being processed. Lines are counted from 1.
         */
        private $line = 1;
        /**
         * @var Internal\XmlRegexes An internal object which contains regular expressions for some common productions from
         * the XML specification.
         */
        private $xmlRegexes;

        /**
         * Puts a new warning into the warnings list.
         * @param string $message The warning message to show to the user.
         * @param string $line The line at which the warning triggered.
         */
        private function addWarning($message, $line)
        {
            $this->warnings[] = new Error($message . " (line " . $line . ")");
        }

        /**
         * Puts a new error into the errors list. Calling this function means the DTD document contains a violation of
         * the XML specification.
         * @param string $message The error message to show to the user.
         * @param int $line The line at which the error triggered.
         */
        private function addFatalError($message, $line)
        {
            $this->errors[] = new Error($message . " (line " . $line . ")");
        }

        /**
         * Checks if the supplied string matches the XML production NAME.
         * @param string $name The string to check for being a NAME.
         * @return bool Is the string a valid NAME production?
         */
        private function isNameValid($name)
        {
            return preg_match("#" . $this->xmlRegexes->Name . "#u", $name) === 1;
        }
        /**
         * Checks if the supplied string matches the XML production NMTOKEN.
         * @param string $nmToken The string to check for being a NMTOKEN.
         * @return bool Is the string a valid NMTOKEN production?
         */
        private function isNmTokenValid($nmToken)
        {
            return preg_match("#" . $this->xmlRegexes->NmToken . "#u", $nmToken) === 1;
        }

        /**
         * Reads characters from the specified position until it encounters a non-whitespace character, then returns
         * the position of this character. If no such character is found, then it returns false.
         *
         * This also increments the line counter if a newline is encountered.
         * @param string $text The text to search through (haystack).
         * @param int $startAt The position from where to start.
         * @param int $length The length of the text.
         * @return bool|int The position of the first non-whitespace character or false if end of text was reached.
         */
        private function findNonSpace($text, $startAt, $length)
        {
            $index = $startAt;
            while ($index < $length)
            {
                $mbCharacter = substr($text, $index, 1);
                if ($mbCharacter === ' ' || $mbCharacter === "\t")
                {
                    $index++;
                }
                else if ($mbCharacter === "\n")
                {
                    $this->line++;
                    $index++;
                }
                else
                {
                    return $index;
                }
            }
            return false;
        }

        /**
         * The string given is split by whitespace into individual words, with the following exceptions:
         * 1. A quote (") open a quoted string which is put into a single token even if it includes whitespaces or apostrophes. This token is ended by the next quote (").
         * 2. The same goes for apostrophe (') except that apostrophe ends the token and quotes inside are not recognized.
         * In both of the cases above, the quotes or apostrophes are put into a single, separate tokens.
         * 3. An opening parenthesis ('(') forces a different mode where tokens are separated by whitespace and the '|' character as in the enumeration or notation attribute type definition in DTD. If two words inside are separated only by whitespace but not by '|', the tokenization fails.
         * Some other caveats apply. Sorry for not detailing them here.
         * @param string $string The string to split into tokens.
         * @param string $tokenizationErrorMessage Out-parameter. If tokenization fails, this is filled with the reason.
         * @return string[]|bool An array of string tokens if tokenization is successful; false otherwise.
         */
        private function tokenize($string, &$tokenizationErrorMessage)
        {
            $length = strlen($string);
            $tokens = [];
            $outerQuote = false;
            $constructingWord = "";
            $afterWhitespace = false;
            $prohibitNonTerminalInsideParentheses = false;
            for ($i = 0; $i < $length; $i++)
            {
                $char = $string[$i];
                switch($char)
                {
                    case "\t":
                    case "\n":
                    case " ":
                        if ($constructingWord !== "" && $outerQuote === false)
                        {
                            $tokens[] = $constructingWord;
                            $constructingWord = "";
                        }
                        else if ($outerQuote !== false)
                        {
                            if ($outerQuote === "(")
                            {
                                if ($constructingWord !== "")
                                {
                                    if ($prohibitNonTerminalInsideParentheses)
                                    {
                                        // Inside an enum, this was done: "( A B | C)" which is prohibited
                                        $tokenizationErrorMessage = "Inside an enumeration, values must be separated by the '|' character, not by whitespace.";
                                        return false;
                                    }
                                    $tokens[] = $constructingWord;
                                    $constructingWord = "";
                                    $prohibitNonTerminalInsideParentheses = true;
                                }
                            }
                            else
                            {
                                $constructingWord .= $char;
                            }
                        }
                        $afterWhitespace = true;
                        break;
                    case "|":
                        $afterWhitespace = false;
                        if ($outerQuote === "(")
                        {
                            if ($constructingWord !== "")
                            {
                                if ($prohibitNonTerminalInsideParentheses)
                                {
                                    // Inside an enum, this was done: "( A B | C)" which is prohibited
                                    $tokenizationErrorMessage = "Inside an enumeration, values must be separated by the '|' character, not by whitespace.";
                                    return false;
                                }
                                $tokens[] = $constructingWord;
                                $constructingWord = "";
                            }
                            $tokens[] = "|";
                            $prohibitNonTerminalInsideParentheses = false;
                        }
                        else
                        {
                            $constructingWord .= "|";
                        }
                        break;
                    case "(":
                        $afterWhitespace = false;
                        if ($outerQuote === false)
                        {
                            $tokens[] = "(";
                            $outerQuote = "(";
                            $prohibitNonTerminalInsideParentheses = false;
                        }
                        else
                        {
                            $constructingWord .= "(";
                        }
                        break;
                    case ")":
                        $afterWhitespace = false;
                        if ($outerQuote === false)
                        {
                            // This character should not be anywhere on its own.
                            $tokenizationErrorMessage = "The ')' character is illegal here.";
                            return false;
                        }
                        else if ($outerQuote === '(')
                        {
                            if ($constructingWord !== "")
                            {
                                $tokens[] = $constructingWord;
                                $constructingWord = "";
                            }
                            $tokens[] = ")";
                            $outerQuote = false;
                        }
                        else
                        {
                            $constructingWord .= ")";
                        }
                        break;
                    case "'":
                    case '"':
                        if ($outerQuote === false && $afterWhitespace === true)
                        {
                            $tokens[] = $char;
                            $outerQuote = $char;
                        }
                        else if ($outerQuote !== false)
                        {
                            if ($outerQuote === $char)
                            {
                                $tokens[] = $constructingWord;
                                $tokens[] = $char;
                                $constructingWord = "";
                                $outerQuote = false;
                            }
                            else
                            {
                                $constructingWord .= $char;
                            }
                        }
                        else
                        {
                            $tokenizationErrorMessage = "Quotes must only appear after whitespace in this context.";
                            return false;
                        }
                        $afterWhitespace = false;
                        break;
                    default:
                        $constructingWord .= $char;
                        $afterWhitespace = false;
                        break;
                }
            }
            if ($constructingWord !== "")
            {
                $tokens[] = $constructingWord;
            }
            return $tokens;
        }

        /**
         * Checks whether the specified haystack begins with the specified needle.
         * @param string $haystack The string whose beginning we search. (TODO grammar)
         * @param string $needle The beginning we search for.
         * @return bool Does the haystack start with the needle?
         */
        private function startsWith($haystack, $needle)
        {
            $length = strlen($needle);
            return (substr($haystack, 0, $length) === $needle);
        }

        /**
         * Evaluates all parameter entity references in the specified text according to the specified mode as per the
         * XML specification. Returns the expanded text. Parameter entities are expanded recursively.
         * @param string $text The text to scan for parameter entities.
         * @param int $peStyle The mode of expansion. Depending on the mode, entities are expanded differently as per the specification.
         * @return string The text with all parameter entities expanded.
         */
        private function evaluatePEReferencesIn($text, $peStyle)
        {
            $matches = [];
            while (preg_match('#(("[^"]*")|(\'[^\']*\')|[^\'"])*%([^;]*);#', $text, $matches, PREG_OFFSET_CAPTURE) === 1)
            {
                $entityBeginsAt = $matches[4][1] - 1;
                $entityEndsBefore = $matches[4][1] + strlen($matches[4][0])+1;
                $entityName = $matches[4][0];
                if (array_key_exists($entityName, $this->parameterEntities))
                {
                    $replacementText = $this->parameterEntities[$entityName]->replacementText;
                    switch($peStyle)
                    {
                        case Internal\PEStyle::IgnoreQuotedText:
                        case Internal\PEStyle::MatchingParentheses: // TODO matching parentheses do not work
                            // The two spaces are mandated by specification to disallow funny stuff
                            $text = substr($text, 0, $entityBeginsAt) . " " . $replacementText . " " . substr($text, $entityEndsBefore);
                            break;
                        case Internal\PEStyle::InEntityDeclaration:
                            // Included in literal.
                            $text = substr($text, 0, $entityBeginsAt) . $replacementText . substr($text, $entityEndsBefore);
                            break;
                        default:
                            trigger_error("Bad peStyle argument.", E_ERROR);
                            break;
                    }
                }
                else
                {
                    $this->addFatalError("Parameter entity '" . $entityName . "' is used, but not defined.", $this->line);
                    return $text;
                }
            }
            return $text;
        }

        /**
         * Parses a parameter entity reference that is not part of any other declaration (i.e. it lies freely in the DTD space.
         * @param string $referenceName Name of the referenced parameter entity.
         */
        private function parseGlobalPEReference($referenceName)
        {
            if (array_key_exists($referenceName, $this->parameterEntities))
            {
                // We must safeguard these member variable values because they will be overwritten by the parseGlobalSpace method.
                $errorCount = count($this->errors);
                $currentLine = $this->line;
                $currentOffset = $this->currentOffset;
                $this->parseGlobalSpace($this->parameterEntities[$referenceName]->replacementText, false);
                if (count($this->errors) > $errorCount)
                {
                    $this->addWarning("The line numbers in the previous errors may not be accurate because these errors occurred within a parameter entity reference.", $currentLine);
                }
                $this->line = $currentLine;
                $this->currentOffset = $currentOffset;
            }
            else {
                $this->addFatalError("The parameter entity '" . $referenceName . "' is not yet declared.", $this->line);
            }
        }

        /**
         * Checks whether the three tokens in $tokens starting $index exist, represent matching quotation marks and then
         * returns the middle token.
         * @param array $tokens An array of tokens.
         * @param int $index The index of the token which should represent the first apostrophe or double quote.
         * @return bool|string The token, if parsed correctly; otherwise false. If false is returned, a fatal error is triggered as well.
         */
        private function parseQuotedString($tokens, $index)
        {
            if ($index + 2 >= count($tokens))
            {
                $this->addFatalError("End of declaration reached while trying to parse a quoted string.", $this->line);
                return false;
            }
            $firstQuote = $tokens[$index];
            $middle = $tokens[$index+1];
            $lastQuote = $tokens[$index+2];
            if ($firstQuote !== "'" && $firstQuote !== '"')
            {
                $this->addFatalError("A quotation mark or apostrophe was expected but '" . $firstQuote . "' is present instead.", $this->line);
                return false;
            }
            if ($firstQuote !== $lastQuote)
            {
                $this->addFatalError("Quotes must match at the ends of each quoted string.", $this->line);
                return false;
            }
            return $middle;
        }

        /**
         * Checks whether the three tokens in $tokens starting $index exist, represent matching quotation marks and then
         * returns the middle token.
         *
         * Notice: This is a wrapper around parseQuotedString that does no additional work.
         * @param array $tokens An array of tokens.
         * @param int $index The index of the token which should represent the first apostrophe or double quote.
         * @return bool|string The token, if parsed correctly; otherwise false. If false is returned, a fatal error is triggered as well.
         */
        private function parseExternalIdentifier($tokens, $index)
        {
            $identifier = $this->parseQuotedString($tokens, $index);
            return $identifier;
        }

        /**
         * Parses an element declaration and adds it to the element list. If it fails, it adds an error to the error list.
         * @param string $declaration The !ELEMENT declaration string, starting just after the !ELEMENT text.
         */
        private function parseElement($declaration)
        {
            $declaration = $this->evaluatePEReferencesIn($declaration, Internal\PEStyle::MatchingParentheses);
            $tokens = array_values(array_filter(preg_split("/\s+/", $declaration)));
            if (count($tokens) === 0)
            {
                $this->addFatalError("An <!ELEMENT> declaration must have a type name.", $this->line);
                return;
            }
            $name = $tokens[0];
            if (!$this->isNameValid($name))
            {
                $this->addFatalError("'{$name}' is not a valid element name.'", $this->line);
            }
            $contentspec = false;
            $isMixed = false;
            if (count($tokens) === 1)
            {
                $this->addFatalError("'{$name}' does not have content type specified.", $this->line);
            }
            else if (count($tokens) === 2)
            {
                if ($tokens[1] === "ANY") { $contentspec = "ANY"; }
                else if ($tokens[1] === "EMPTY") { $contentspec = "EMPTY"; }
            }
            if ($contentspec === false)
            {
                array_shift($tokens);
                $contentspec = implode("", $tokens);
                $contentspec = str_replace(" ", "", $contentspec);
                $contentspec = str_replace("\t", "", $contentspec);
                $contentspec = str_replace("\n", "", $contentspec);
                $isMixed = $this->startsWith($contentspec, "(#PCDATA");
                // We should verify the legality of the context regular expression here, but we don't need it.
                // In future versions of the DTDParser, this should probably be done.
                // TODO differentiate mixed from pure text content
            }
            if (array_key_exists($name, $this->elements))
            {
                if ($this->elements[$name]->contentSpecification === Element::CONTENT_SPECIFICATION_NOT_GIVEN)
                {
                    $this->elements[$name]->contentSpecification = $contentspec;
                    $this->elements[$name]->mixed = $isMixed;
                }
                else
                {
                    $this->addFatalError("This element ('{$name}') was already declared.", $this->line);
                }
                return;
            }
            else
            {
                $this->elements[$name] = new Element($name, $contentspec, $isMixed);
            }
        }

        /**
         * Parses an ATTLIST declaration and adds the information to the element list. If it fails, an error will be
         * added to the error list.
         * @param string $markupDeclaration The !ATTLIST declaration string, starting just after the !ATTLIST text.
         */
        private function parseAttlist($markupDeclaration)
        {
            $markupDeclaration = $this->evaluatePEReferencesIn($markupDeclaration, Internal\PEStyle::IgnoreQuotedText);
            $tokens = $this->tokenize($markupDeclaration, $tokenizationError);
            if ($tokens === false)
            {
                $this->addFatalError("ATTLIST declaration could not be tokenized: " . $tokenizationError, $this->line);
                return;
            }
            if (count($tokens) === 0)
            {
                $this->addFatalError("An <!ATTLIST> declaration must have a type name.", $this->line);
                return;
            }
            $elementType = $tokens[0];
            if (!$this->isNameValid($elementType))
            {
                $this->addFatalError("'{$elementType}' is not a valid element name.'", $this->line);
            }
            $tokenId = 1;
            $attributeName = false;
            $attributeType = false;
            $attributeEnumeration = [];
            $attributeDefaultValue = false;
            $attributeDefaultType = false;
            $state = Internal\AttlistMode::NeedName;
            while ($tokenId < count($tokens))
            {
                $token = $tokens[$tokenId];
                if ($state === Internal\AttlistMode::NeedName)
                {
                    if (!$this->isNameValid($token)) { $this->addFatalError("'{$token}' is not a valid attribute name.'", $this->line); }
                    $attributeName = $token;
                    $state = Internal\AttlistMode::NeedAttType;
                }
                else if ($state === Internal\AttlistMode::NeedAttType)
                {
                    $state = Internal\AttlistMode::NeedDefaultDecl;
                    switch($token)
                    {
                        case "CDATA":
                        case "ID":
                        case "IDREF":
                        case "IDREFS":
                        case "ENTITY":
                        case "ENTITIES":
                        case "NMTOKEN":
                        case "NMTOKENS":
                            $attributeType = $token;
                            break;
                        case "(":
                            $attributeType = Attribute::ATTTYPE_ENUMERATION;
                            $state = Internal\AttlistMode::InsideEnumeration_NeedValue;
                            break;
                        case "NOTATION":
                            $attributeType = Attribute::ATTTYPE_NOTATION; // TODO validity checks
                            $state = Internal\AttlistMode::AfterNOTATION;
                            break;
                        default:
                            $this->addFatalError("The attribute '" . $attributeName . "' has a declared type that does not exist.", $this->line);
                            break;
                    }
                }
                else if ($state === Internal\AttlistMode::InsideEnumeration_NeedValue)
                {
                    if (!$this->isNmTokenValid($token))
                    {
                        $this->addFatalError("An enumerated type must only have NMTOKENs as possible values.", $this->line);
                        return;
                    }
                    $attributeEnumeration[] = $token;
                    $state = Internal\AttlistMode::InsideEnumeration_NeedSeparator;
                }
                else if ($state === Internal\AttlistMode::InsideEnumeration_NeedSeparator)
                {
                    if ($token === "|")
                    {
                        $state = Internal\AttlistMode::InsideEnumeration_NeedValue;
                    }
                    else if ($token === ")")
                    {
                        $state = Internal\AttlistMode::NeedDefaultDecl;
                    }
                    else
                    {
                        $this->addFatalError("In the attribute '{$attributeName}' enumeration, the token '|' or ')' was expected.", $this->line);
                    }
                }
                else if ($state === Internal\AttlistMode::AfterNOTATION)
                {
                    if ($token === "(")
                    {
                        $state = Internal\AttlistMode::InsideEnumeration_NeedValue;
                    }
                    else
                    {
                        $this->addFatalError("The attribute '" . $attributeName . "' is declared NOTATION but misses a notations enumeration.", $this->line);
                    }
                }
                else if ($state === Internal\AttlistMode::NeedDefaultDecl)
                {
                    switch($token)
                    {
                        case "#REQUIRED":
                        case "#IMPLIED":
                            $attributeDefaultValue = "";
                            $attributeDefaultType = $token;
                            break;
                        case "#FIXED":
                            $attributeDefaultType = "#FIXED";
                            if ($tokenId + 3 < count($tokens))
                            {
                                if  (($tokens[$tokenId+1] === "'" && $tokens[$tokenId+3] === "'") ||
                                    ($tokens[$tokenId+1] === '"' && $tokens[$tokenId+3] === '"'))
                                {
                                    // Parameter entities should not be expanded here.
                                    $attributeDefaultValue = $tokens[$tokenId+2];
                                }
                                else
                                {
                                    $this->addFatalError("The attribute '" . $attributeName . "' has an #FIXED declaration.", $this->line);
                                }
                                $tokenId+=3;
                            }
                            else
                            {
                                $this->addFatalError("The attribute '" . $attributeName . "' has a #FIXED declaration, but its default value is not provided.", $this->line);
                            }
                            break;
                        case "'":
                        case '"':
                            $attributeDefaultType = Attribute::DEFAULT_IMPLICIT_DEFAULT;
                            if ($tokenId + 2 < count($tokens))
                            {
                                if  ($tokens[$tokenId+2] === $token)
                                {
                                    // Parameter entities should not be expanded here.
                                    $attributeDefaultValue = $tokens[$tokenId+1];
                                }
                                else
                                {
                                    $this->addFatalError("The attribute '" . $attributeName . "' starts quoting a default value, but does not finish this quotation.", $this->line);
                                }
                                $tokenId += 2;
                            }
                            else
                            {
                                $this->addFatalError("The attribute '" . $attributeName . "' starts a default value declaration, but does not finish it.", $this->line);
                            }
                            break;
                        default:
                            $this->addFatalError("The attribute '" . $attributeName . "' has an invalid DefaultDeclaration.", $this->line);
                            break;
                    }
                    $attributeCreated = new Attribute($attributeName, $attributeType, $attributeDefaultType, $attributeDefaultValue, $attributeEnumeration);
                    if (!array_key_exists($elementType, $this->elements))
                    {
                        $this->elements[$elementType] = new Element($elementType, Element::CONTENT_SPECIFICATION_NOT_GIVEN, false);
                    }
                    if (array_key_exists($attributeName, $this->elements[$elementType]->attributes))
                    {
                        // At user option, for interopability, the XML processor may issue a warning.
                        // This processor chooses not to issue it. At any rate, we must keep the previous definition.
                    }
                    else
                    {
                        $this->elements[$elementType]->attributes[$attributeName] = $attributeCreated;
                    }
                    $attributeName = false;
                    $attributeDefaultType = false;
                    $attributeDefaultValue = false;
                    $attributeEnumeration = false;
                    $attributeType = false;
                    $state = Internal\AttlistMode::NeedName;
                }
                $tokenId++;
            }

            if ($attributeName !== false)
            {
                $this->addFatalError("An attribute definition inside the ATTLIST was not completed.", $this->line);
            }
        }

        /**
         * Parses a notation declaration and adds it to the notations list. If it fails, an error is added to the error list.
         * @param string $markupDeclaration The !NOTATION declaration string, starting just after the !NOTATION text.
         */
        private function parseNotation($markupDeclaration)
        {
            $markupDeclaration = $this->evaluatePEReferencesIn($markupDeclaration, Internal\PEStyle::IgnoreQuotedText);
            $tokens = $this->tokenize($markupDeclaration, $tokenizationError);
            if ($tokens === false)
            {
                $this->addFatalError("Notation declaration could not be tokenized: " . $tokenizationError, $this->line);
                return;
            }

            if (count($tokens) === 5 || count($tokens) === 8)
            {
                $error = false;
                $name = $tokens[0];
                if (!$this->isNameValid($name))
                {
                    $this->addFatalError("'" . $name . "' is not a valid NOTATION name.", $this->line);
                    return;
                }
                $externalIDType = $tokens[1];
                $systemId = "";
                $publicId = "";
                if ($tokens[2] !== $tokens[4]) { $error = true; }
                if ($tokens[2] !== "'" && $tokens[2] !== '"') { $error = true; }
                if ($externalIDType !== "PUBLIC" && $externalIDType !== "SYSTEM")
                {
                    $this->addFatalError("Notations must be either PUBLIC or SYSTEM.", $this->line);
                    return;
                }
                if ($externalIDType === "SYSTEM")
                {
                    $systemId = $tokens[3];
                }
                if ($externalIDType === "PUBLIC")
                {
                    $publicId = $tokens[3];
                }
                if (count($tokens) === 8)
                {
                    if ($tokens[5] !== $tokens[7]) { $error = true; }
                    if ($tokens[5] !== "'" && $tokens[5] !== '"') { $error = true; }
                    $systemId = $tokens[6];
                    if ($externalIDType !== "PUBLIC")
                    {
                        $this->addFatalError("A public identifier was provided even thought the notation is not declared PUBLIC.", $this->line);
                        return;
                    }
                }
                if ($error)
                {
                    $this->addFatalError("External ID's in '" . $markupDeclaration . "' are not properly quoted.", $this->line);
                    return;
                }

                $notation = new Notation($name, $systemId, $publicId);
                if (array_key_exists($name, $this->notations))
                {
                    $this->addFatalError("Notation '" . $name . "' is already declared.", $this->line);
                    return;
                }
                $this->notations[$name] = $notation;
            }
            else
            {
                $this->addFatalError("'" . $markupDeclaration . "' is not a well-formed NOTATION declaration.", $this->line);
            }
        }

        /**
         * Parses an !ENTITY declaration and adds it to the general entities or parameter entities list. If it fails,
         * it adds an error to the error list. If it's a parameter entity, its replacement text is generated at this point
         * by evaluating parameter entity references inside its value.
         * @param string $markupDeclaration An !ENTITY declaration string, starting just after the !ENTITY text.
         */
        private function parseEntityDeclaration($markupDeclaration)
        {
            $tokenizationError = "";
            $markupDeclaration = $this->evaluatePEReferencesIn($markupDeclaration, Internal\PEStyle::IgnoreQuotedText);
            $tokens = $this->tokenize($markupDeclaration, $tokenizationError);
            if ($tokens === false)
            {
                $this->addFatalError("Entity declaration could not be tokenized: " . $tokenizationError, $this->line);
                return;
            }
            if (count($tokens) < 4)
            {
                $this->addFatalError("'" . $markupDeclaration . "' is not a well-formed ENTITY declaration.", $this->line);
                return;
            }
            $tokenId = 0;
            $isParametric = false;
            $isExternal = false;
            $publicIdentifier = false;
            $systemIdentifier = false;
            $notation = false;
            if ($tokens[$tokenId] === "%")
            {
                $isParametric = true;
                $tokenId++;
            }
            $name = $tokens[$tokenId];
            $tokenId++;
            if (!$this->isNameValid($name))
            {
                $this->addFatalError("'" . $name . "' is not a valid ENTITY name.", $this->line);
                return;
            }
            if ($tokens[$tokenId] === "SYSTEM" || $tokens[$tokenId] === "PUBLIC")
            {
                if ($tokens[$tokenId] === "SYSTEM")
                {
                    if ($tokenId + 3 <= count($tokens) - 1)
                    {
                        if ($tokens[$tokenId + 1] === $tokens[$tokenId + 3])
                        {
                            if ($tokens[$tokenId + 1 ] === "'" || $tokens[$tokenId + 1] === '"')
                            {
                                $systemIdentifier = $tokens[$tokenId + 2];
                            }
                            else
                            {
                                $this->addFatalError("'" . $markupDeclaration . "' is not a well-formed SYSTEM external ENTITY because its SystemId was not properly quoted.", $this->line);
                                return;
                            }
                        }
                        else
                        {
                            $this->addFatalError("'" . $markupDeclaration . "' is not a well-formed SYSTEM external ENTITY because its SystemId quotes do not match.", $this->line);
                            return;
                        }
                    }
                    else
                    {
                        $this->addFatalError("'" . $markupDeclaration . "' is not a well-formed SYSTEM external ENTITY because it could not be properly tokenized.", $this->line);
                        return;
                    }
                    $tokenId += 4;
                }
                else // Public identifier
                {
                    $tokenId++;
                    $publicIdentifier = $this->parseExternalIdentifier($tokens, $tokenId);
                    if ($publicIdentifier === false) {
                        $this->addFatalError("Parsing the public identifier of '" . $markupDeclaration . "' failed.", $this->line);
                        return;
                    }

                    $tokenId += 3;
                    $systemIdentifier = $this->parseExternalIdentifier($tokens, $tokenId);
                    if ($publicIdentifier === false) {
                        $this->addFatalError("Parsing the system identifier of '" . $markupDeclaration . "' failed.", $this->line);
                        return;
                    }

                    $tokenId += 3;
                }
                $replacementText = "";
                $isExternal = true;
                if ($tokenId < count($tokens))
                {
                    if ($tokens[$tokenId] === "NDATA")
                    {
                        $tokenId++;
                        if ($tokenId === count($tokens)-1)
                        {
                            $notation = $tokens[$tokenId];
                            $tokenId++;
                            if (!$this->isNameValid($notation))
                            {
                                $this->addFatalError("In a general entity declaration, NDATA was followed by '" . $notation . "' which is not a Name.", $this->line);
                                return;
                            }
                            if (!array_key_exists($notation, $this->notations))
                            {
                                $this->addFatalError("An ENTITY declaration refers to the notation '" . $notation . "' which is not yet declared.", $this->line);
                                return;
                            }
                            if ($isParametric)
                            {
                                $this->addFatalError("Parametric entities may not have an NDATA specifier.", $this->line);
                                return;
                            }
                        }
                        else
                        {
                            $this->addFatalError("In a general entity declaration, the keyword NDATA must be followed by a Name only. It is followed by something else, however.", $this->line);
                            return;
                        }
                    }
                    else
                    {
                        $this->addFatalError("NDATA or end of entity declaration expected", $this->line);
                        return;
                    }
                }
                if ($this->shouldLoadExternalEntities)
                {
                    if (file_exists($systemIdentifier))
                    {
                        $externalContent = file_get_contents($systemIdentifier);
                        if ($externalContent !== false)
                        {
                            // This should probably, at user option, be permitted.
                            $this->addWarning("This DTD parser is not programmed to parse additional external entities.", $this->line);
                        }
                        else
                        {
                            $this->addWarning("An external parameter entity is declared but reading from the file given by system identifier failed.", $this->line);
                        }
                    }
                    else
                    {
                        $this->addWarning("An external parameter entity is declared but its system identifier does not point to a file.", $this->line);
                    }
                }
            }
            else if ($tokens[$tokenId] === "'" || $tokens[$tokenId] === '"')
            {
                if ($tokens[$tokenId] === $tokens[$tokenId+2] && count($tokens) === $tokenId+3)
                {
                    $replacementText = $tokens[$tokenId+1];
                    $replacementText = $this->evaluatePEReferencesIn($replacementText, Internal\PEStyle::InEntityDeclaration);
                    if (strpos($replacementText, "%") !== false)
                    {
                        $this->addFatalError("Entities cannot contain the character '%' unless as part of a parameter entity reference.", $this->line);
                        return;
                    }
                    $tokenId += 3;
                }
                else
                {
                    $this->addFatalError("'" . $markupDeclaration . "' is not a well-formed ENTITY because it contains additional illegal markup.", $this->line);
                    return;
                }
            }
            else
            {
                $this->addFatalError("'" . $markupDeclaration . "' is not a well-formed ENTITY.", $this->line);
                return;
            }
            if ($tokenId !== count($tokens))
            {
                $this->addFatalError("'" . $markupDeclaration . "' contains additional illegal tokens near the end.", $this->line);
                return;
            }
            if ($isParametric)
            {
                if (!array_key_exists($name, $this->parameterEntities))
                {
                    // We could issue a warning (at user option), but we must not issue an error.
                    $this->parameterEntities[$name] = new ParameterEntity($name, $replacementText, $isExternal, $systemIdentifier, $publicIdentifier);
                }
            }
            else
            {
                if (!array_key_exists($name, $this->generalEntities))
                {
                    $this->generalEntities[$name] = new GeneralEntity($name, $replacementText, $isExternal, $systemIdentifier, $publicIdentifier, $notation);
                }
            }
        }

        /**
         * Parses the specified string as a DTD markup declaration.
         * @param string $markupDeclaration A DTD markup declaration to parse.
         */
        private function parseMarkupDeclaration($markupDeclaration)
        {
            if ($this->startsWith($markupDeclaration, "<!ELEMENT ") || $this->startsWith($markupDeclaration, "<!ELEMENT\n") || $this->startsWith($markupDeclaration, "<!ELEMENT\t"))
                $this->parseElement(substr($markupDeclaration, strlen("<!ELEMENT "), -1));
            else if ($this->startsWith($markupDeclaration, "<!ATTLIST ")|| $this->startsWith($markupDeclaration, "<!ATTLIST\n") || $this->startsWith($markupDeclaration, "<!ATTLIST\t"))
                $this->parseAttlist(substr($markupDeclaration, strlen("<!ATTLIST "), -1));
            else if ($this->startsWith($markupDeclaration, "<!NOTATION ")|| $this->startsWith($markupDeclaration, "<!NOTATION\n") || $this->startsWith($markupDeclaration, "<!NOTATION\t"))
                $this->parseNotation(substr($markupDeclaration, strlen("<!NOTATION "), -1));
            else if ($this->startsWith($markupDeclaration, "<!ENTITY ")|| $this->startsWith($markupDeclaration, "<!ENTITY\n") || $this->startsWith($markupDeclaration, "<!ENTITY\t"))
                $this->parseEntityDeclaration(substr($markupDeclaration, strlen("<!ENTITY "), -1));
            else
            {
                $this->addFatalError("This declaration type does not exist (only ELEMENT, ATTLIST, NOTATION and ENTITY are possible).", $this->line);
            }
        }

        /**
         * Parses a processing instruction and adds it to the list of processing instruction. Adds an error if the parsing fails.
         * @param string $processingInstruction A processing instruction text, starting just after the left angle bracket and the question mark.
         */
        private function parseProcessingInstruction($processingInstruction)
        {
            $split = explode(' ', $processingInstruction, 2);
            if (count($split) !== 2)
            {
                $this->addFatalError("This processing instruction does not have a target.", $this->line);
                return;
            }
            if (!$this->isNameValid($split[0]))
            {
                $this->addFatalError("The target of a processing instruction must be a Name.", $this->line);
                return;
            }
            $this->processingInstructions = new ProcessingInstruction($split[0], $split[1]);
        }

        /**
         * Parses the given text as the contents of a DTD file.
         * @param string $text The text of the DTD file.
         * @param bool $isInternalSubset True, if the text is the contents of the internal subset of an XML file instead. There are additional restrictions by the specification on what can be present in the internal subset.
         */
        private function parseGlobalSpace($text, $isInternalSubset)
        {
            $this->line = 1;
            $this->currentOffset = 0;
            $includeSectionsOpened = 0;
            $ignoreSectionsOpened = 0;

            // 1. Normalize end-of-lines as per unicode spec
            $text = str_replace("\r\n", "\n", $text); // Quotes necessary, with apostrophes, it would not work.
            $text = str_replace("\r", "\n", $text);   // Quotes necessary, with apostrophes, it would not work.
            // str_replace only counts a \n as a newline if it is within
            // quotes.

            // 2. Remove comments
            // Comments should probably be saved as well. Someone might want to access them. In future versions of the
            // parser, this functionality should be added. Plus, multiline comments mess with line numbers in errors.
            $text = preg_replace('/<!--(([^-])|(-[^-]))*-->/', '', $text);
            $length = strlen($text);

            // 3. Go through the text, searching for
            //  a) %ref; Parameter entity reference.
            //  b) <!ELEMENT Name TextNoGt>
            //  c) <!ATTLIST Name TextNoGt>
            //  d) <!ENTITY (%) Name SYSTEMLITERALCONTAINSGT>
            //  e) <!NOTATION Name SYSTEMLITERALCONTAINSGT>
            //  f) <![ INCLUDE [ ]]>
            //  g) <![ IGNORE [ ]]>
            //  h) <!-- causes error, it should have been removed
            $this->currentOffset = $this->findNonSpace($text, $this->currentOffset, $length);
            while ($this->currentOffset !== false)
            {
                if (substr($text, $this->currentOffset, 3) === "]]>")
                {
                    if ($ignoreSectionsOpened > 0)
                    {
                        $ignoreSectionsOpened--;
                    }
                    else if ($includeSectionsOpened > 0)
                    {
                        $includeSectionsOpened--;
                    }
                    else
                    {
                        $this->addFatalError("The token ']]>' does not close any conditional section at this position.", $this->line);
                    }
                    $this->currentOffset += 3;
                }
                else if (substr($text, $this->currentOffset, 3) === "<![")
                {
                    if ($isInternalSubset)
                    {
                        $this->addFatalError("Internal subsets cannot contain conditional sections.", $this->line);
                    }
                    if ($ignoreSectionsOpened > 0)
                    {
                        $ignoreSectionsOpened++;
                        $this->currentOffset += 3;
                    }
                    else
                    {
                        // This is a conditional section.
                        $nextOpeningBrace = strpos($text, "[", $this->currentOffset + 3);
                        if ($nextOpeningBrace === false)
                        {
                            $this->addFatalError("The conditional section is missing its second opening bracket.", $this->line);
                            break;
                        }
                        $includeOrIgnore = substr($text, $this->currentOffset + 3, $nextOpeningBrace - $this->currentOffset - 3);
                        $includeOrIgnore = trim($this->evaluatePEReferencesIn($includeOrIgnore, Internal\PEStyle::IgnoreQuotedText));
                        if ($includeOrIgnore === "INCLUDE")
                        {
                            $includeSectionsOpened++;
                            $this->currentOffset = $nextOpeningBrace+1;
                        }
                        else if ($includeOrIgnore === "IGNORE")
                        {
                            $ignoreSectionsOpened++;
                            $this->currentOffset = $nextOpeningBrace+1;
                        }
                        else
                        {
                            $this->addFatalError("The marked section was neither INCLUDE nor IGNORE. No other marked sections are allowed in a DTD.", $this->line);
                            $this->currentOffset = $nextOpeningBrace + 1;
                        }
                    }
                }
                elseif ($ignoreSectionsOpened == 0)
                {
                    if (substr($text, $this->currentOffset, 1) === "%")
                    {
                        // This is a parameter-entity reference.
                        $endingColon = strpos($text, ";", $this->currentOffset+1);
                        if ($endingColon === false)
                        {
                            $this->addFatalError("The parameter entity reference is not finished.", $this->line);
                            break;
                        }
                        else
                        {
                            $PEReferenceText = substr($text, $this->currentOffset+1, $endingColon - $this->currentOffset -1);
                            $this->parseGlobalPEReference($PEReferenceText);
                            $this->line += substr_count($PEReferenceText, "\n");
                            $this->currentOffset = $endingColon+1;
                        }
                    }
                    else if (substr($text, $this->currentOffset, 4) === "<!--")
                    {
                        $this->addFatalError("The comment contained two consecutive dashes '--' which is not permitted. Perhaps your file contained nested comments?", $this->line);
                        break;
                    }
                    else if (substr($text, $this->currentOffset, 2) === "<!")
                    {
                        // This is a declaration.
                        $tagBeginsAt = $this->currentOffset;
                        $inQuotes = false; $inApostrophes = false;
                        $this->currentOffset += 2;
                        $tagEndsAt = false;
                        while ($this->currentOffset < $length)
                        {
                            $character = substr($text, $this->currentOffset, 1);
                            if ($character === "'")
                            {
                                if (!$inQuotes) { $inApostrophes = !$inApostrophes;}
                            }
                            else if ($character === '"')
                            {
                                if (!$inApostrophes) { $inQuotes = !$inQuotes; }
                            }
                            else if ($character === '>')
                            {
                                if (!$inApostrophes && !$inQuotes)
                                {
                                    $tagEndsAt = $this->currentOffset;
                                    $this->currentOffset++;
                                    break;
                                }
                            }
                            $this->currentOffset++;
                        }
                        if ($tagEndsAt === false)
                        {
                            $this->addFatalError("The markup declaration is not finished.", $this->line);
                            break;
                        }
                        else
                        {
                            $markupDeclaration = substr($text, $tagBeginsAt, $tagEndsAt - $tagBeginsAt+1);
                            $this->parseMarkupDeclaration($markupDeclaration);
                            $this->line += substr_count($markupDeclaration, "\n");
                        }
                    }
                    else if (substr($text, $this->currentOffset, 2) === "<?")
                    {
                        $endAt = strpos($text, "?>", $this->currentOffset + 2);
                        if ($endAt === false)
                        {
                            $this->addFatalError("The processing instruction is not finished.", $this->line);
                            break;
                        }
                        $processing_instruction = substr($text, $this->currentOffset, $endAt - $this->currentOffset + 2);
                        $this->parseProcessingInstruction($processing_instruction);
                        $this->line += substr_count($processing_instruction, "\n");
                        $this->currentOffset = $endAt+2;
                    }
                    else if (substr($text, $this->currentOffset, 1) === "<")
                    {
                        // This is a declaration.
                        $this->addFatalError("The character '<' here must be immediately followed by '!' or '?'." , $this->line);
                        break;
                    }
                    else
                    {
                        $character = substr($text, $this->currentOffset, 1);
                        $this->addFatalError("The character '" . $character . "' is not permitted here (only '%', '< !' and '< ?' and possibly ']]>' are permitted)." , $this->line);
                        break;
                    }
                }
                else
                {
                    $this->currentOffset++;
                }
                // Find next character.
                $this->currentOffset = $this->findNonSpace($text, $this->currentOffset, $length);
            }
            if ($includeSectionsOpened > 0 || $ignoreSectionsOpened > 0)
            {
                $this->addFatalError("A conditional section was not closed by the end of the DTD.", $this->line);
            }
        }

        /**
         * Creates a new DTD object from the specified DTD text and internal subset. These texts are parsed immediately.
         * @param string $text The contents of a DTD file.
         * @param string $internalSubset The contents of an internal subset.
         */
        private function __construct($text, $internalSubset)
        {
            $this->xmlRegexes = new Internal\XmlRegexes();
            $this->parseGlobalSpace($internalSubset, true); // Per XML specification, section 2.8, "If both the external and internal subsets are used, the internal subset must be considered to occur before the external subset."
            $this->parseGlobalSpace($text, false);
        }

        /**
         * Parse the text given as though it were part of a .dtd file and return a \Soothsilver\DtdParser\DTD instance, even if
         * parsing fails.
         * @param string $text UTF-8 text to parse
         * @param string $internalSubset optionally, parse this XML internal subset in addition to the main DTD text given as the first parameter
         * @return DTD Object representing the parsed DTD document.
         */
        public static function parseText($text, $internalSubset = "")
        {
            $dtd = new DTD($text, $internalSubset);
            return $dtd;
        }
    }

    /**
     * Represents an XML notation declaration
     * See also: http://www.w3.org/TR/REC-xml/#Notations
     */
    class Notation
    {
        /**
         * @var string Notation name
         */
        public $name = "";
        /**
         * @var string Public ID or an empty string if there is no public ID
         */
        public $publicID = "";
        /**
         * @var string System ID (mandatory)
         */
        public $systemID = "";

        /**
         * Creates a new Notation.
         * @param string $name The notation name.
         * @param string $systemID The notation's system id.
         * @param string $publicID The notation's public id.
         */
        public function  __construct($name, $systemID, $publicID)
        {
            $this->name =$name;
            $this->systemID = $systemID;
            $this->publicID = $publicID;
        }
    }

    /**
     * Represents an attribute definition of a single attribute.
     */
    class Attribute
    {
        /**
         * The CDATA attribute type.
         */
        const ATTTYPE_CDATA = "CDATA";
        /**
         * The ID attribute type.
         */
        const ATTTYPE_ID = "ID";
        /**
         * The IDREF attribute type.
         */
        const ATTTYPE_IDREF = "IDREF";
        /**
         * The IDREFS attribute type.
         */
        const ATTTYPE_IDREFS = "IDREFS";
        /**
         * The ENTITY attribute type.
         */
        const ATTTYPE_ENTITY = "ENTITY";
        /**
         * The ENTITIES attribute type.
         */
        const ATTTYPE_ENTITIES = "ENTITIES";
        /**
         * The NMTOKEN attribute type.
         */
        const ATTTYPE_NMTOKEN = "NMTOKEN";
        /**
         * The NMTOKENS attribute type.
         */
        const ATTTYPE_NMTOKENS = "NMTOKENS";
        /**
         * This attribute's type is an enumeration.
         * The exact value of this constant is not relevant, it just has to be constant. The permitted values of the enumeration
         * are listed in the $enumeration array.
         */
        const ATTTYPE_ENUMERATION = "##ENUMERATION_INTERNAL_IDENTIFIER##";
        /**
         * The NOTATION attribute type. The permitted values are listed in the $enumeration array.
         */
        const ATTTYPE_NOTATION = "NOTATION";
        /**
         * The #REQUIRED default declaration. This attribute has no default value and must be present in valid XML files.
         */
        const DEFAULT_REQUIRED = "#REQUIRED";
        /**
         * The #IMPLIED default declaration. This attribute has no default value but it doesn't need to be present in valid XML files.
         */
        const DEFAULT_IMPLIED = "#IMPLIED";
        /**
         * The #FIXED default declaration. This attribute must be present and must have the value specified in $defaultValue in valid XML files.
         */
        const DEFAULT_FIXED = "#FIXED";
        /**
         * This attribute's default declaration is given as a default string. This means that if the attribute is present in an XML file, its value
         * is used. If the attribute is not present, the file is still valid, and the attribute is considered to have the value
         * specified in the $defaultValue of this attribute.
         * The exact value of this constant is not relevant, it just has to be constant.    *
         */
        const DEFAULT_IMPLICIT_DEFAULT = "##DEFAULT_VALUE_IF_EMPTY_INTERNAL_IDENTIFIER##";
        /**
         * Name of the attribute.
         * @var string
         */
        public $name;
        /**
         * Type of the attribute, given as one of the ATTTYPE_ constants of this class.
         * @var string
         */
        public $type;
        /**
         * An array of possible values of this attribute, if it's an enumeration type, or an array of possible notations, if it's a notation attribute.
         * If it's neither a notation or an enumeration-type attribute, this is FALSE.
         * @var array|bool
         */
        public $enumeration = array();
        /**
         * The default declaration of this attribute, given as one of the DEFAULT_ constants of this class.
         * @var string
         */
        public $defaultType;
        /**
         * Default value of this attribute, if this is relevant. It is relevant for DEFAULT_FIXED and DEFAULT_IMPLICIT_DEFAULT.  Otherwise it's an empty string.
         * @var string
         */
        public $defaultValue;

        /**
         * Initializes a new instance of the Attribute class that represents the definition of a single attribute.
         * @param string $name Name of the attribute.
         * @param string $type Type of the attribute, given as one of the ATTTYPE_ constants of this class.
         * @param string $defaultType The default declaration of this attribute, given as one of the DEFAULT_ constants of this class.
         * @param string $defaultValue Default value of this attribute, if this is relevant. It is relevant for DEFAULT_FIXED and DEFAULT_IMPLICIT_DEFAULT. Otherwise it's an empty string.
         * @param array|bool $enumeration An array of possible values of this attribute, if it's an enumeration type, or an array of possible notations, if it's a notation attribute. If it's neither a notation or an enumeration-type attribute, this should be false.
         */
        public function __construct($name, $type, $defaultType, $defaultValue, $enumeration = false)
        {
            $this->name = $name;
            $this->enumeration = $enumeration;
            $this->type = $type;
            $this->defaultType = $defaultType;
            $this->defaultValue = $defaultValue;
        }
    }

    /**
     * Represents a processing instruction.
     */
    class ProcessingInstruction
    {
        /**
         * @var string $target The target of the processing instruction.
         */
        public $target;
        /**
         * @var string $data Data passed to the target of the processing instruction.
         */
        public $data;

        /**
         * Creates a new processing instruction.
         * @param string $target The target of the instruction.
         * @param string $data The data passed to the target.
         */
        public function __construct($target, $data)
        {
            $this->target = $target;
            $this->data = $data;
        }
    }

    /**
     * Represents the definition of an element type. This includes attributes permitted for this element.
     */
    class Element
    {
        /**
         * The "ANY" content specification string. An element with this content specification may contain any number of any defined elements in any order.
         */
        const CONTENT_SPECIFICATION_ANY = "ANY";
        /**
         * The "EMPTY" content specification string. An element with this content specification may not contain anything, not even whitespace.
         */
        const CONTENT_SPECIFICATION_EMPTY = "EMPTY";
        /**
         * There was no !ELEMENT declaration for this element type in the DTD, but there was an ATTLIST declaration.
         * This is a boolean rather than a string.
         */
        const CONTENT_SPECIFICATION_NOT_GIVEN = false;

        /**
         * A value that indicates whether the element has mixed content.
         * @note {
         * A content declaration of pure text "(#PCDATA)" is considered to be mixed content.
         * }
         * @var boolean
         */
        public $mixed = false;

        /**
         * Name of the element. Formally, this is called the element type.
         * @var string
         */
        public $type = "";
        /**
         * Content specification of the element. For example, this could be (#PCDATA), EMPTY or (butterfly|mouse)+.
         * @var string
         */
        public $contentSpecification = Element::CONTENT_SPECIFICATION_NOT_GIVEN;
        /**
         * An array of all permitted attributes of this element.
         * @var Attribute[]
         */
        public $attributes = array();

        /**
         * Initializes a new instance of the Element class.
         * @param string $type Name of the element. Formally, this is called the element type.
         * @param string $contentModel Content specification of the element. For example, this could be (#PCDATA), EMPTY or (butterfly|mouse)+.
         * @param boolean $mixed A value that indicates whether this is a mixed element.
         */
        public function __construct($type, $contentModel, $mixed)
        {
            $this->mixed = $mixed;
            $this->type = $type;
            $this->contentSpecification = $contentModel;
        }

        /**
         * Returns a value that indicates whether the element has mixed content.
         * @note {
         * A content declaration of pure text "(#PCDATA)" is considered to be mixed content.
         * }
         * @return boolean
         */
        public function isMixed()
        {
            return $this->mixed;
        }

        /**
         * Returns a value that indicates whether the element may contain only text (that means, its content specification is (#PCDATA) or (#PCDATA)*.
         * @return bool
         */
        public function isPureText()
        {
            return $this->contentSpecification === "(#PCDATA)" || $this->contentSpecification === "(#PCDATA)*";
        }
    }

    /**
     * Represents the declaration of a general entity.
     */
    class GeneralEntity
    {
        /**
         * Name of the entity.
         * @var string
         */
        public $name = "";
        /**
         * Replacement text of the entity. Entity references within this text are not expanded.
         * @note {
         * In XML specification, this is called the entity value. It is considered replacement text only after the
         * entity references are expanded.
         * }
         * @var string
         */
        public $replacementText = "";
        /**
         * If this is an external entity, then this is the notation that should be used to process it. It is captured
         * from the NDATA value.
         * @var string|bool The value in the NDATA declaration, or FALSE if this is not an external entity.
         */
        public $notation = false;
        /**
         * A value that indicates whether this is an external entity.
         * @var bool
         */
        public $external = false;
        /**
         * If this is an external entity, then this is its System ID. Otherwise, it's false.
         * @var string|false
         */
        public $systemId = false;
        /**
         * If this is an external entity with a public ID, then this is its Public ID. Otherwise, it's false.
         * @var string|false
         */
        public $publicId = false;

        /**
         * Initializes a new instance of the GeneralEntity class. For internal use only.
         * @param string $name Name of the entity.
         * @param string $replacementText Replacement text of the entity. Entity references within this text are not expanded.
         * @param boolean $external A value that indicates whether this is an external entity.
         * @param string|false $systemId If this is an external entity, then this is its System ID. Otherwise, it's false.
         * @param string|false $publicId If this is an external entity with a public ID, then this is its Public ID. Otherwise, it's false.
         * @param string|false $notation The NDATA value, or false if this is not an external entity declaration.
         */
        public function __construct($name, $replacementText, $external, $systemId, $publicId, $notation)
        {
            $this->name = $name;
            $this->replacementText = $replacementText;
            $this->notation = $notation;
            $this->external = $external;
            $this->systemId = $systemId;
            $this->publicId = $publicId;
        }
    }

    /**
     * Represents a parameter entity declaration.
     */
    class ParameterEntity
    {
        /**
         * Name of the parameter entity.
         * @var string
         */
        public $name = "";
        /**
         * Replacement text of the entity. All parameter entity references inside the replacement text are already fully expanded.
         * @var string
         */
        public $replacementText = "";
        /**
         * A value that indicates whether this is an external entity. External entities are not loaded.
         * @var bool
         */
        public $external = false;
        /**
         * If this is an external entity, then this is its System ID. Otherwise, it's false.
         * @var string|false
         */
        public $systemId = false;
        /**
         * If this is an external entity with a public ID, then this is its Public ID. Otherwise, it's false.
         * @var string|false
         */
        public $publicId = false;

        /**
         * Initializes a new instance of the ParamaterEntity class. For internal use only.
         * @param string $name Name of the parameter entity.
         * @param string $replacementText Replacement text of the entity. All parameter entity references inside the replacement text are already fully expanded.
         * @param boolean $external A value that indicates whether this is an external entity. External entities are not loaded.
         * @param string|false $systemId If this is an external entity, then this is its System ID. Otherwise, it's false.
         * @param string|false $publicId If this is an external entity with a public ID, then this is its Public ID. Otherwise, it's false.
         */
        public function __construct($name, $replacementText, $external, $systemId, $publicId)
        {
            $this->name = $name;
            $this->replacementText = $replacementText;
            $this->external = $external;
            $this->systemId = $systemId;
            $this->publicId = $publicId;
        }
    }

    /**
     * Represents an error or a warning produced during the parsing of a DTD file.
     */
    class Error
    {
        /**
         * Error message indicating where and how the XML specification was violated.
         * @var string
         */
        private $message;

        /**
         * Gets the error message indicating where and how the XML specification was violated.
         * @return string Error message indicating where and how the XML specification was violated.
         */
        public function getMessage()
        {
            return $this->message;
        }

        /**
         * Constructs a new instance of the Error class. For internal use only.
         * @param string $message The error message including line number.
         */
        public function __construct($message)
        {
            $this->message = $message;
        }
    }
}

namespace Soothsilver\DtdParser\Internal {

    /**
     * Contains regular expressions for various productions in the XML specification
     */
    class XmlRegexes {
        /**
         * @var string The NameChar production.
         */
        public $NameChar;
        /**
         * @var string The NameStartChar production.
         */
        public $NameStartChar;
        /**
         * @var string The NAME production.
         */
        public $Name;
        /**
         * @var string The NMTOKEN production.
         */
        public $NmToken;

        /**
         * Initializes members with productions from the specification.
         */
        public function __construct()
        {
            $this->NameChar = "[:A-Z_a-z\\-.0-9\\xC0-\\xD6\\xD8-\\xF6\\xF8-\\x{2FF}\\x{370}-\\x{37D}\\x{37F}-\\x{1FFF}\\x{200C}-\\x{200D}\\x{2070}-\\x{218F}\\x{2C00}-\\x{2FEF}\\x{3001}-\\x{D7FF}\\x{F900}-\\x{FDCF}\\x{FDF0}-\\x{FFFD}\\x{10000}-\\x{EFFFF}]";
            $this->NameStartChar = "[:A-Z_a-z\-.0-9\\xB7\\xC0-\\xD6\\xD8-\\xF6\\xF8-\\x{37D}\\x{37F}-\\x{1FFF}\\x{200C}-\\x{200D}\\x{203F}-\\x{2040}\\x{2070}-\\x{218F}\\x{2C00}-\\x{2FEF}\\x{3001}-\\x{D7FF}\\x{F900}-\\x{FDCF}\\x{FDF0}-\\x{FFFD}\\x{10000}-\\x{EFFFF}]";
            $this->Name = "{$this->NameStartChar}{$this->NameChar}*";
            $this->NmToken = "{$this->NameChar}+";
        }
    }

    /**
     * Represents the parser state during the parsing of an ATTLIST declaration
     */
    abstract class AttlistMode {
        /**
         * The parser now expects an attribute name.
         */
        const NeedName = 0;
        /**
         * The parser now expects an attribute type, such as ID or IDREF.
         */
        const NeedAttType = 1;
        /**
         * The parser now expects an opening parenthesis character '(' because the token NOTATION just occurred.
         */
        const AfterNOTATION = 2;
        /**
         * The parser now expects a possible value of an enumeration and it is inside a parenthesis block.
         */
        const InsideEnumeration_NeedValue = 3;
        /**
         * The parser finished parsing the attribute type and now expects a default declaration, such as #IMPLIED.
         */
        const NeedDefaultDecl = 4;
        /**
         * The parser now expects either the closing parenthesis character ')' or the enumeration value separator '|'.
         */
        const InsideEnumeration_NeedSeparator = 5;
    }

    /**
     * Represents the state of the parser that determines what should be done about parameter entities found.
     */
    abstract class PEStyle {
        /**
         * Do not expand parameter entities inside quotes. Expand all other parameter entities by adding a single space
         * before and after the replacement text, as per the specification.
         */
        const IgnoreQuotedText = 0;
        /**
         * Do not expand parameter entities inside quotes. Expand all other parameter entities by adding a single space
         * before and after the replacement text, as per the specification.
         *
         * In addition, add an error if, after replacement is done, there are unpaired parentheses. This is not currently
         * being done and should be improved in a future version.
         */
        const MatchingParentheses = 1;
        /**
         * Expand all parameter entities but do not add a single space before and after the replacement text, because we
         * are now in the middle of an entity declaration.
         */
        const InEntityDeclaration = 2;
    }
}
/**
 * @}
 */