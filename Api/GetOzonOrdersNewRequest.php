<?php
/*
 *  Copyright 2024.  Baks.dev <admin@baks.dev>
 *  
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *  
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *  
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Ozon\Orders\Api;

use BaksDev\Ozon\Api\Ozon;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;

/**
 * Информация о заказах
 */
final class GetOzonOrdersNewRequest extends Ozon
{
    private ?DateTimeImmutable $fromDate = null;

    /**
     * Возвращает информацию последних заказах со статусом:
     *
     * awaiting_packaging - заказ находится в обработке
     *
     * @see https://docs.ozon.ru/api/seller/#operation/PostingAPI_GetFbsPostingListV3
     *
     */
    public function findAll(?DateInterval $interval = null): Generator|bool
    {
        $dateTimeNow = new DateTimeImmutable();

        if(!$this->fromDate)
        {
            // Новые заказы за последние 15 минут (планировщик на каждую минуту)
            $this->fromDate = $dateTimeNow->sub($interval ?? DateInterval::createFromDateString('15 minutes'));

            /**
             * В 3 часа ночи получаем заказы за сутки
             */

            $currentHour = $dateTimeNow->format('H');
            $currentMinute = $dateTimeNow->format('i');

            if($currentHour === '03' && $currentMinute >= '00' && $currentMinute <= '05')
            {
                $this->fromDate = $dateTimeNow->sub(DateInterval::createFromDateString('1 day'));
            }
        }

        $data['dir'] = 'DESC'; // сортировка
        $data['limit'] = 1000; // Количество значений в ответе
        $data['filter']['since'] = $this->fromDate->format(DateTimeInterface::W3C); // Дата начала периода (Y-m-d\TH:i:sP)
        $data['filter']['to'] = $dateTimeNow->format(DateTimeInterface::W3C);   // Дата конца периода (Y-m-d\TH:i:sP)
        $data['filter']['status'] = 'awaiting_packaging'; // Статус отправления

        $response = $this->TokenHttpClient()
            ->request(
                'POST',
                '/v3/posting/fbs/list',
                ['json' => $data],
            );

        $content = $response->toArray(false);

        if($response->getStatusCode() !== 200)
        {
            foreach($content['errors'] as $error)
            {
                $this->logger->critical($error['code'].': '.$error['message'], [self::class.':'.__LINE__]);
            }

            return false;
        }

        foreach($content['result']['postings'] as $order)
        {
            yield new NewOzonOrderDTO($order, $this->getProfile());
        }
    }
}