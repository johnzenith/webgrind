<?php
/**
 * Class for reading datafiles generated by Webgrind_Preprocessor
 *
 * @package Webgrind
 * @author Jacob Oettinger
 */
class Webgrind_Reader
{
    /**
     * File format version that this reader understands
     */
    const FILE_FORMAT_VERSION = 7;

    /**
     * Binary number format used.
     * @see http://php.net/pack
     */
    const NR_FORMAT = 'V';

    /**
     * Size, in bytes, of the above number format
     */
    const NR_SIZE = 4;

    /**
     * Length of a call information block
     */
    const CALLINFORMATION_LENGTH = 4;

    /**
     * Length of a function information block
     */
    const FUNCTIONINFORMATION_LENGTH = 6;

    /**
     * Address of the headers in the data file
     *
     * @var int
     */
    private $headersPos;

    /**
     * Array of addresses pointing to information about functions
     *
     * @var array
     */
    private $functionPos;

    /**
     * Array of headers
     *
     * @var array
     */
    private $headers=null;

    /**
     * Format to return costs in
     *
     * @var string
     */
    private $costFormat;


    /**
     * Constructor
     * @param string Data file to read
     * @param string Format to return costs in
     */
    function __construct($dataFile, $costFormat) {
        $this->fp = @fopen($dataFile,'rb');
        if (!$this->fp)
            throw new Exception('Error opening file!');

        $this->costFormat = $costFormat;
        $this->init();
    }

    /**
     * Initializes the parser by reading initial information.
     *
     * Throws an exception if the file version does not match the readers version
     *
     * @return void
     * @throws Exception
     */
    private function init() {
        list($version, $this->headersPos, $functionCount) = $this->read(3);
        if ($version!=self::FILE_FORMAT_VERSION)
            throw new Exception('Datafile not correct version. Found '.$version.' expected '.self::FILE_FORMAT_VERSION);
        $this->functionPos = $this->read($functionCount);
    }

    /**
     * Returns number of functions
     * @return int
     */
    function getFunctionCount(){
        return count($this->functionPos);
    }

    /**
     * Returns information about function with nr $nr
     *
     * @param $nr int Function number
     * @return array Function information
     */
    function getFunctionInfo($nr) {
        $this->seek($this->functionPos[$nr]);

        list($line, $summedSelfCost, $summedInclusiveCost, $invocationCount, $calledFromCount, $subCallCount) = $this->read(self::FUNCTIONINFORMATION_LENGTH);

        $this->seek(self::NR_SIZE*self::CALLINFORMATION_LENGTH*($calledFromCount+$subCallCount), SEEK_CUR);
        $file = $this->readLine();
        $function = $this->readLine();

           $result = array(
            'file'=>$file,
            'line'=>$line,
               'functionName'=>$function,
               'summedSelfCost'=>$summedSelfCost,
               'summedInclusiveCost'=>$summedInclusiveCost,
               'invocationCount'=>$invocationCount,
            'calledFromInfoCount'=>$calledFromCount,
            'subCallInfoCount'=>$subCallCount
           );
        $result['summedSelfCost'] = $this->formatCost($result['summedSelfCost']);
        $result['summedInclusiveCost'] = $this->formatCost($result['summedInclusiveCost']);

        return $result;
    }

    /**
     * Returns information about positions where a function has been called from
     *
     * @param $functionNr int Function number
     * @param $calledFromNr int Called from position nr
     * @return array Called from information
     */
    function getCalledFromInfo($functionNr, $calledFromNr){
        $this->seek(
            $this->functionPos[$functionNr]
            + self::NR_SIZE
            * (self::CALLINFORMATION_LENGTH * $calledFromNr + self::FUNCTIONINFORMATION_LENGTH)
            );

        $data = $this->read(self::CALLINFORMATION_LENGTH);

        $result = array(
            'functionNr'=>$data[0],
            'line'=>$data[1],
            'callCount'=>$data[2],
            'summedCallCost'=>$data[3]
        );

        $result['summedCallCost'] = $this->formatCost($result['summedCallCost']);

        return $result;
    }

    /**
     * Returns information about functions called by a function
     *
     * @param $functionNr int Function number
     * @param $subCallNr int Sub call position nr
     * @return array Sub call information
     */
    function getSubCallInfo($functionNr, $subCallNr) {
        // Sub call count is the second last number in the FUNCTION_INFORMATION block
        $this->seek($this->functionPos[$functionNr] + self::NR_SIZE * (self::FUNCTIONINFORMATION_LENGTH - 2));
        $calledFromInfoCount = $this->read();
        $this->seek( ( ($calledFromInfoCount+$subCallNr) * self::CALLINFORMATION_LENGTH + 1 ) * self::NR_SIZE,SEEK_CUR);
        $data = $this->read(self::CALLINFORMATION_LENGTH);

        $result = array(
            'functionNr'=>$data[0],
            'line'=>$data[1],
            'callCount'=>$data[2],
            'summedCallCost'=>$data[3]
        );

        $result['summedCallCost'] = $this->formatCost($result['summedCallCost']);

        return $result;
    }

    /**
     * Returns array of defined headers
     *
     * @return array Headers in format array('header name'=>'header value')
     */
    function getHeaders() {
        if ($this->headers==null) { // Cache headers
            $this->seek($this->headersPos);
            $this->headers['runs'] = 0;
            while ($line=$this->readLine()) {
                $parts = explode(': ',$line);
                if ($parts[0] == 'summary') {
                    $this->headers['runs']++;
                    if (isset($this->headers['summary']))
                        $this->headers['summary'] += $parts[1];
                    else
                        $this->headers['summary'] = $parts[1];
                } else {
                    $this->headers[$parts[0]] = $parts[1];
                }
            }
        }
        return $this->headers;
    }

    /**
     * Returns value of a single header
     *
     * @return string Header value
     */
    function getHeader($header) {
        $headers = $this->getHeaders();
        return isset($headers[$header]) ? $headers[$header] : '';
    }

    /**
     * Formats $cost using the format in $this->costFormat or optionally the format given as input
     *
     * @param int $cost Cost
     * @param string $format 'percent', 'msec' or 'usec'
     * @return int Formatted cost
     */
    function formatCost($cost, $format=null) {
        if ($format==null)
            $format = $this->costFormat;

        if ($format == 'percent') {
            $total = $this->getHeader('summary');
            $result = ($total==0) ? 0 : ($cost*100)/$total;
            return number_format($result, 2, '.', '');
        }

        if ($format == 'msec') {
            return round($cost/1000, 0);
        }

        // Default usec
        return $cost;
    }

    private function read($numbers=1) {
        $values = unpack(self::NR_FORMAT.$numbers,fread($this->fp,self::NR_SIZE*$numbers));
        if ($numbers==1)
            return $values[1];
        else
            return array_values($values); // reindex and return
    }

    private function readLine() {
        $result = fgets($this->fp);
        if ($result)
            return trim($result);
        else
            return $result;
    }

    private function seek($offset, $whence=SEEK_SET) {
        return fseek($this->fp, $offset, $whence);
    }

}
