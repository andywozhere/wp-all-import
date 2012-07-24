<?php
/**
 * @author Olexandr Zanichkovsky <olexandr.zanichkovsky@zophiatech.com>
 * @package General
 */

require_once dirname(__FILE__) . '/XmlImportConfig.php';
require_once dirname(__FILE__) . '/ast/XmlImportAstSequence.php';
require_once dirname(__FILE__) . '/ast/XmlImportAstText.php';
require_once dirname(__FILE__) . '/ast/XmlImportAstPrint.php';
require_once dirname(__FILE__) . '/ast/XmlImportAstInteger.php';
require_once dirname(__FILE__) . '/ast/XmlImportAstFloat.php';
require_once dirname(__FILE__) . '/ast/XmlImportAstString.php';
require_once dirname(__FILE__) . '/ast/XmlImportAstXPath.php';
require_once dirname(__FILE__) . '/ast/XmlImportAstFunction.php';
require_once dirname(__FILE__) . '/ast/XmlImportAstWith.php';
require_once dirname(__FILE__) . '/ast/XmlImportAstForeach.php';
require_once dirname(__FILE__) . '/ast/XmlImportAstIf.php';
require_once dirname(__FILE__) . '/ast/XmlImportAstMath.php';

/**
 * Is used to generate a PHP code from AST (Abstract Syntax Tree)
 */
class XmlImportTemplateCodeGenerator
{

  /**
   * Top level statement sequence
   *
   * @var XmlImportAstSequence
   */
  private $sequence;

  /**
   * statement sequence stack
   *
   * @var array
   */
  private $sequenceStack = array();

  /**
   * SimpleXmlElement variable number
   *
   * @var int
   */
  private $xmlVariableNumber = 0;

  /**
   * Previous statement
   *
   * @var XmlImportAstStatement
   */
  private $previousStatement = null;

  /**
   * Stack of SimpleXmlElement instances
   *
   * @var array
   */
  private $xmlStack = array();

  /**
   * Whether PHP tag is open
   *
   * @var bool
   */
  private $isPhpTagOpen = false;

  /**
   * Creates new instance
   *
   * @param XmlImportAstSequence $sequence
   */
  public function __construct(XmlImportAstSequence $sequence)
  {
    $this->sequence = $sequence;
  }

  /**
   * Generates the code and returns the filename under which it was stored in
   * cache directory. If $filename is null then it is generated automatically
   *
   * @param string $filename
   * @return string
   */
  public function generate($filename = null)
  {
  	$result = '';
    if (count($this->sequence->getVariables()))
    {
      $var = '$x' . $this->xmlVariableNumber++;
      array_push($this->xmlStack, $var);
      $result .= $this->openPhpTag() . $var . ' = $this->xml;' ;
    }
    $result .= $this->generateForSequence($this->sequence);
	
    if (count($this->sequence->getVariables()))
    {
      array_pop($this->xmlStack);
    }	
    if (is_null($filename))
    {
       $filename = tempnam(XmlImportConfig::getInstance()->getCacheDirectory(), 'xim');
    }
	
    file_put_contents($filename, $result);
    return $filename;
  }

  /**
   * Generates code for a statement sequence
   *
   * @param XmlImportAstSequence $sequence
   * @return string
   */
  private function generateForSequence(XmlImportAstSequence $sequence)
  {
    array_push($this->sequenceStack, $sequence);
    $result = '';
    if (count($sequence->getVariableDefinitions()) > 0)
    {
      $result .= $this->openPhpTag();
      foreach ($sequence->getVariableDefinitions() as $xpath)
      {
        $result .= PHP_EOL . str_replace('{{XML}}', $this->xmlStack[count($this->xmlStack) - 1], $xpath);
      }
      $result .= PHP_EOL;
    }
    foreach ($sequence->getStatements() as $statement)
    {
      $result .= $this->generateForStatement($statement);
    }
    array_pop($this->sequenceStack);
    return $result;
  }

  /**
   * Generates code for a statement
   *
   * @param XmlImportAstStatement $statement
   * @return string
   */
  private function generateForStatement(XmlImportAstStatement $statement)
  {
    $result = '';
    if ($statement instanceof XmlImportAstText)
    {
      $result .= $this->closePhpTag();
      $text = preg_replace('%<\?|\?>%', '<?php echo "$0"; ?>', $statement->getValue()); // escape php tags
      if ($this->previousStatement instanceof XmlImportAstPrint && (strpos($text, "\n") === 0 || strpos($text, "\r\n") === 0))
      {
        $result .= PHP_EOL;
      }
      $result .= $text;
    }
    else
    {
      $result .= $this->openPhpTag();
      if ($statement instanceof XmlImportAstPrint)
      {		
        $result .= 'echo ';
        $result .= $this->generateForExpression($statement->getValue(), true) . ';';
      }
      elseif ($statement instanceof XmlImportAstWith)
      {
        $var = '$x' . $this->xmlVariableNumber++;
        $result .= PHP_EOL . $var . ' = ' ;
        $result .= $this->generateForExpression($statement->getXpath()) . ';' . PHP_EOL;

        array_push($this->xmlStack, $var);
        $result .= 'if (' . $var . ' !== false && count(' . $var . ') > 0) :' . PHP_EOL . $var .
          ' = ' . $var . '[0];' . PHP_EOL;
        $result .= $this->generateForSequence($statement->getBody());
        array_pop($this->xmlStack);
        $result .= $this->openPhpTag() . PHP_EOL . 'endif;' . PHP_EOL;
      }
      elseif ($statement instanceof XmlImportAstForeach)
      {
        $var = '$x' . $this->xmlVariableNumber++;
        $result .= PHP_EOL . 'foreach (' . $this->generateForExpression($statement->getXPath()) .
          ' as ' . $var . ') :' . PHP_EOL;
        array_push($this->xmlStack, $var);
        $result .= $this->generateForSequence($statement->getBody());
        $result .= $this->openPhpTag() . PHP_EOL . 'endforeach;' . PHP_EOL;
        array_pop($this->xmlStack);
      }
      elseif ($statement instanceof XmlImportAstIf)
      {
        $result .= PHP_EOL . 'if (' . $this->generateForExpression($statement->getCondition()) .  ') :' . PHP_EOL;
        $result .= $this->generateForSequence($statement->getIfBody());
        foreach ($statement->getElseIfs() as $elseif)
        {
          $result .= $this->openPhpTag() . PHP_EOL . 'elseif (' . $this->generateForExpression($elseif->getCondition()) . ') :' . PHP_EOL;
          $result .= $this->generateForSequence($elseif->getBody());
        }
        if (!is_null($body = $statement->getElseBody()))
        {
          $result .= $this->openPhpTag() . PHP_EOL . 'else :' . PHP_EOL;
          $result .= $this->generateForSequence($body);
        }
        $result .= $this->openPhpTag() . PHP_EOL . 'endif;' . PHP_EOL;
      }
	  
    }
    $this->previousStatement = $statement;
    return $result;
  }

  /**
   * Generates code for expression
   *
   * @param XmlImportAstExpression $expression
   * @param bool $inPrint whether in print or in clause or function argument
   * @return string
   */
  private function generateForExpression(XmlImportAstExpression $expression, $inPrint = false)
  {
	
    switch (get_class($expression))
    {
      case 'XmlImportAstString':
        $result = '"' . $this->getEscapedValue($expression->getValue()) . '"';
        break;

      case 'XmlImportAstInteger':
      case 'XmlImportAstFloat':
        $result = $expression->getValue();
        break;

      case 'XmlImportAstXPath':
        if ($inPrint)
        {
          $variables = $this->sequenceStack[count($this->sequenceStack) - 1]->getVariables();
          $result = '$this->getValue(' . $variables[$expression->getValue()] . ')';
        }
        else
        {
          $variables = $this->sequenceStack[count($this->sequenceStack) - 1]->getVariables();
          $result = $variables[$expression->getValue()];
        }
        break;

      case 'XmlImportAstFunction':
        $result = $this->generateForFunction($expression);
		break;
	  case 'XmlImportAstMath':
        $result = $this->generateForMath($expression);
        break;
    }
    return $result;
  }

  /**
   * Generates code for a function
   *
   * @param XmlImportAstFunction $function
   * @return string
   */
  private function generateForFunction(XmlImportAstFunction $function)
  {
    $result = $function->getName() . '(';
    $arguments = $function->getArguments();
    for($i = 0; $i < count($arguments); $i++)
    {		
      $result .= $this->generateForExpression($arguments[$i]);
      if ($i < (count($arguments) - 1))
        $result .= ', ';
    }
    $result .= ')';
	
    return $result;
  }
  
  /**
   * Generates code for a function
   *
   * @param XmlImportAstFunction $function
   * @return string
   */
  private function generateForMath(XmlImportAstMath $math)
  {
    $result = '';
    $arguments = $math->getArguments();
    for($i = 0; $i < count($arguments); $i++)
    {		
      $result .= $this->generateForExpression($arguments[$i], true);      	  
    }
    
    return 'number_format('.str_replace("\"", "", $result).',2)';
  }

  /**
   * Add PHP open tag if needed
   *
   * @return string
   */
  private function openPhpTag()
  {
    $result = '';
    if (!$this->isPhpTagOpen)
    {
      $this->isPhpTagOpen = true;
      $result = '<?php ';
    }
    return $result;
  }

  /**
   * Adds PHP close tag if needed
   *
   * @return string
   */
  private function closePhpTag()
  {
    $result = '';
    if ($this->isPhpTagOpen)
    {
      $this->isPhpTagOpen = false;
      $result = '?>';
    }
    return $result;
  }

  /**
   * Gets escaped value
   *
   * @return string
   */
  private function getEscapedValue($value)
  {
    $escapedValue = strtr($value, array(
    	"\n" => "\\n",
    	"\t" => "\\t",
    	"\r" => "\\r",
    	"$" => "\\$",
    	"\"" => "\\\"",
    	"\\" => "\\\\",
    ));
    return $escapedValue;
  }
}