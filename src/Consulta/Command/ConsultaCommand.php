<?php

namespace ConsultaEmpresa\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use ConsultaEmpresa\Scrapers\Cliente;
use ConsultaEmpresa\Scrapers\Prospect;
use Symfony\Component\Console\Helper\ProgressBar;
use function GuzzleHttp\json_decode;
use function GuzzleHttp\json_encode;
use Swaggest\JsonSchema\Schema;
use Symfony\Component\Console\Command\LockableTrait;

class ConsultaCommand extends Command
{
    use LockableTrait;
    /**
     * @var Cliente|Prospect $processor
     */
    private $processor;
    /**
     * @var OutputInterface
     */
    private $output;
    /**
     * Output para CSV dos dados da API
     *
     * @var boolean
     */
    protected $csv;
    protected $limite;
    protected function configure(): void
    {
        $this
            ->setName('consulta')
            ->setDescription('Realiza consulta de CNPJ.')
            ->setDefinition([
                new InputOption('arquivo', 'a', InputOption::VALUE_OPTIONAL, 'Arquivo para importação'),
                new InputOption(
                    'tipo',
                    't',
                    InputOption::VALUE_OPTIONAL,
                    "Tipo de importação, <info>c</info> para clientes e <info>p</info> para prospects\n".
                    "Necessário apenas para importação via API.\n\n".
                    "O tipo é definido pelo cabeçalho do xlsx em importação via arquivo."
                ),
                new InputOption('apirequest', 'r', InputOption::VALUE_OPTIONAL, 'Endpoint de API que retorne uma lista de clientes'),
                new InputOption('apisend', 's', InputOption::VALUE_OPTIONAL, 'Endpoint de API para devolução de dados coletados'),
                new InputOption('csv', 'c', InputOption::VALUE_OPTIONAL, 'Output para CSV dos dados da API'),
                new InputOption('limite', 'l', InputOption::VALUE_REQUIRED, 'Limita a quantidade de CNPJ para processar via API')
            ])
            ->setHelp(<<<HELP
                O comando <info>consulta</info> realiza consulta de empresa.
                
                Maiores informações:
                    https://github.com/librecodecoop/consulta-empresa-anvisa-cli
                HELP
            )
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->lock()) {
            $output->writeln('Este comando já está em execução em outro processo.');
            return 0;
        }
        try {
            $arquivo = $input->getOption('arquivo');
            $apirequest = $input->getOption('apirequest');
            $apisend = $input->getOption('apisend');
            $this->output = $output;
            $this->csv = $input->hasOption('csv');
            $this->limite = $input->getOption('limite');
            if ($this->limite && !is_numeric($this->limite)) {
                throw new \Exception(
                    '<error>Limite deve ser numérico</error>'
                );
            }
            if ($arquivo) {
                $this->processFile($arquivo);
            } elseif ($apirequest || $apisend) {
                $this->processApi($apirequest, $apisend, $input->hasOption('mock'));
            } else {
                throw new \Exception(
                    '<error>Necessário informar arquivo ou uma API para realizar a importação</error>'
                );
            }
        } catch (\Exception $e) {
            $output->writeln(
                $e->getMessage()."\n".
                "Execute o comando que segue para mais informações:\n".
                '  consulta --help'
            );
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    private function processFile($filename)
    {
        if (!file_exists($filename)) {
            throw new \Exception(
                <<<MESSAGE
                <error>Arquivo [$filename] não existe</error>
                
                Informe um arquivo <info>xlsx</info> para entrada de dados.
                MESSAGE
            );
        }
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filename);
        $worksheet = $spreadsheet->getActiveSheet();
        $lastCol = Coordinate::columnIndexFromString($worksheet->getHighestColumn());
        switch ($lastCol) {
            case 8:
                $type = 'cliente';
                break;
            case 16:
                $type = 'prospect';
                break;
            default:
                throw new \Exception(
                    <<<MESSAGE
                    <error>Formato de arquivo inválido</error>
                    
                    O arquivo precisa ter 8 colunas para clientes e 16 para prospects
                    MESSAGE
                );
        }
        $className = 'ConsultaEmpresa\Scrapers\\'.ucfirst($type);
        $this->processor = new $className();
        // Cria coluna de status
        $worksheet->getCellByColumnAndRow($lastCol, 1)->setValue('Status');
        $highestRow = $worksheet->getHighestRow();
        $this->output->writeln('Importando '. $type);
        $progressBar = new ProgressBar($this->output, $highestRow);
        $progressBar->start();
        for ($row = 2; $row <= $highestRow; ++$row) {
            $cnpj = $worksheet->getCellByColumnAndRow(1, $row)->getValue();
            $cnpj = str_pad($cnpj, 14, 0, STR_PAD_LEFT);
            $data = $this->processor->processCnpj($cnpj);
            $this->processor->write($worksheet, $row, $lastCol, $data);
            $progressBar->advance();
        }
        $progressBar->setMessage('Salvando arquivo');
        $this->saveFile($spreadsheet, $filename);
        $progressBar->finish();
        $this->output->writeln('');
        return 0;
    }

    private function saveFile($spreadsheet, $filename)
    {
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $filename = explode(DIRECTORY_SEPARATOR, $filename);
        $key = array_key_last($filename);
        $filename[$key] = 'output-'.$filename[$key];
        $filename = implode(DIRECTORY_SEPARATOR, $filename);
        $writer->save($filename);
    }

    private function processApi($apirequest, $apisend)
    {
        if (!$apirequest) {
            throw new \Exception("<error>Argumento [apirequest] precisa ter uma url válida</error>");
        }
        if (!$apisend) {
            throw new \Exception("<error>Argumento [apisend] precisa ter uma url válida</error>");
        }

        // Carrega JSON
        if ($this->output->isVerbose()) {
            $this->output->writeln('Solicitando dados da ANVISA e processando. url:['.$apirequest.']');
        }
        $json = file_get_contents($apirequest);
        if ($this->output->isVerbose()) {
            $this->output->writeln('Retorno da API:');
            $this->output->writeln('<info>'.$json.'</info>');
            $this->output->writeln('Validando dados');
        }
        $list = json_decode($json);
        $this->validateSchema($list);

        // Processa
        $total = count($list->CLIENTES);
        $this->output->writeln('Total de CNPJ a serem processados: <info>'.$total.'</info>');
        $progressBar = new ProgressBar($this->output, $this->limite?:$total);
        $progressBar->start();
        $this->processor = new \ConsultaEmpresa\Scrapers\Cliente();
        if ($this->csv) {
            $csv = fopen('output-'.date('YmdHis').'.csv', 'w');
            fputcsv($csv, [
                'cnpj',
                'correlatos-autorizacao',
                'correlatos-validade',
                'medicamentos-autorizacao',
                'medicamentos-validade',
                'saneantes-autorizacao',
                'saneantes-validade',
                'status',
                'data-consulta'
            ]);
        }
        foreach ($list->CLIENTES as $key => $cliente) {
            if ($this->limite && $key == $this->limite) {
                break;
            }
            $data = $this->processor->processCnpj($cliente->CNPJ);
            if ($this->csv) {
                $processed = [
                    $cliente->CNPJ,
                    $data['correlatos']['autorizacao'],
                    $data['correlatos']['validade'],
                    $data['medicamentos']['autorizacao'],
                    $data['medicamentos']['validade'],
                    $data['saneantes']['autorizacao'],
                    $data['saneantes']['validade'],
                    isset($data['status'])?$data['status']:'',
                    date('Y-m-d H:i:s')
                ];
                fputcsv($csv, $processed);
            }
            $list->ANVISA[$key] = $this->convertRowToJson($cliente, $data);
            $progressBar->advance();
        }
        if ($this->csv) {
            fclose($csv);
        }
        unset($list->CLIENTES);
        $progressBar->finish();
        $this->output->writeln('');

        $this->sendDataToApi($list, $apisend);
        $progressBar->setMessage('FIM');
        $this->output->writeln('');
    }
    
    private function sendDataToApi($list, $apisend)
    {
        $processed = json_encode($list, JSON_UNESCAPED_SLASHES);
        $return = file_get_contents($apisend, false, stream_context_create(['http' =>
            [
                'method'  => 'POST',
                'header'  => 'Content-type: application/x-www-form-urlencoded',
                'content' => $processed
            ]
        ]));
        if ($this->output->isVerbose()) {
            $this->output->writeln('URL para devolução de dados: [<info>'.$apisend.'</info>]');
            $this->output->writeln('Dados enviados para a API:');
            $this->output->writeln('<info>'.$processed.'</info>');
            $this->output->writeln('Dados retornados pela API:');
            $this->output->writeln('<info>'.$return.'</info>');
        }
    }

    /**
     * Valida o schema dos dados retornados pela API
     */
    private function validateSchema($list)
    {
        $schema = <<<SCHEMA
            {
              "type": "object",
              "title": "Validação de schema retornado pela API",
              "required": [
                "CLIENTES"
              ],
              "properties": {
                "CLIENTES": {
                  "type": "array",
                  "title": "Lista de clientes",
                  "items": {
                    "type": "object",
                    "title": "Cliente",
                    "required": [
                      "FILIAL",
                      "CNPJ"
                    ],
                    "properties": {
                      "FILIAL": {
                        "type": "string",
                        "title": "Filial, não é utilizado pela importação mas a API solicita esta informação ao devolver os dados importados",
                        "default": "",
                        "examples": [
                          " "
                        ]
                      },
                      "CNPJ": {
                        "type": "string",
                        "title": "CNPJ sem máscara",
                        "default": "",
                        "examples": [
                          "49150956000169"
                        ],
                        "pattern": "^(\\\\d{14})$"
                      }
                    }
                  }
                }
              }
            }
            SCHEMA;
        $schema = Schema::import(\json_decode($schema));
        $schema->in($list);
    }

    private function convertRowToJson($cliente, $data)
    {
        $row['FIL'] = $cliente->FILIAL;
        $row['CNPJ'] = $cliente->CNPJ;
        $row['XANVCOR'] = $data['correlatos']['autorizacao'];
        if ($data['correlatos']['validade']) {
            $row['XDTACOR'] =
                \DateTime::createFromFormat('d/m/Y', $data['correlatos']['validade'])
                    ->format('Ymd');
        } else {
            $row['XDTACOR'] = '';
        }
        $row['XANVMED'] = $data['medicamentos']['autorizacao'];
        if ($data['medicamentos']['validade']) {
            $row['XDTAMED'] =
                \DateTime::createFromFormat('d/m/Y', $data['medicamentos']['validade'])
                    ->format('Ymd');
        } else {
            $row['XDTAMED'] = '';
        }
        $row['XANVSAN'] = $data['saneantes']['autorizacao'];
        if ($data['saneantes']['validade']) {
            $row['XDTASAN'] =
                \DateTime::createFromFormat('d/m/Y', $data['saneantes']['validade'])
                    ->format('Ymd');
        } else {
            $row['XDTASAN'] = '';
        }
        return $row;
    }
}
