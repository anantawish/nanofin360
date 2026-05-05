<?php
declare(strict_types=1);

final class LoanCalculator
{
    private float $principal;
    private float $flatRatePct;
    private int $termMonths;
    private float $paymentPerMonth;
    private float $monthlyRate;
    private float $effectiveAnnualRatePct;

    public function __construct(float $principal, float $flatRatePct, int $termMonths, ?float $paymentOverride = null)
    {
        $this->principal = max(0.0, $principal);
        $this->flatRatePct = max(0.0, $flatRatePct);
        $this->termMonths = max(1, $termMonths);

        $payment = $paymentOverride !== null && $paymentOverride > 0
            ? $paymentOverride
            : $this->calculateFlatPayment();

        $this->paymentPerMonth = round(max(0.0, $payment), 2);
        $this->monthlyRate = $this->solveMonthlyRate($this->principal, $this->paymentPerMonth, $this->termMonths);
        $this->effectiveAnnualRatePct = $this->monthlyRate > 0
            ? round((pow(1 + $this->monthlyRate, 12) - 1) * 100, 6)
            : 0.0;
    }

    public function getPrincipal(): float
    {
        return $this->principal;
    }

    public function getFlatRatePct(): float
    {
        return $this->flatRatePct;
    }

    public function getTermMonths(): int
    {
        return $this->termMonths;
    }

    public function getPaymentPerMonth(): float
    {
        return $this->paymentPerMonth;
    }

    public function getMonthlyRate(): float
    {
        return $this->monthlyRate;
    }

    public function getEffectiveAnnualRatePct(): float
    {
        return $this->effectiveAnnualRatePct;
    }

    public function calculateFlatPayment(): float
    {
        if ($this->principal <= 0 || $this->termMonths <= 0) {
            return 0.0;
        }
        $years = $this->termMonths / 12;
        $totalInterest = $this->principal * ($this->flatRatePct / 100) * $years;
        $totalRepay = $this->principal + $totalInterest;
        return $totalRepay / $this->termMonths;
    }

    public function buildAmortizationSchedule(string $startDate = ''): array
    {
        $rows = [];
        $balance = $this->principal;
        $date = new DateTimeImmutable($startDate !== '' ? $startDate : date('Y-m-d'));

        for ($i = 1; $i <= $this->termMonths; $i++) {
            $dueDate = $date->modify('+' . ($i - 1) . ' month')->format('Y-m-d');
            $interest = $this->monthlyRate > 0 ? round($balance * $this->monthlyRate, 2) : 0.0;
            $principal = round($this->paymentPerMonth - $interest, 2);

            if ($this->monthlyRate <= 0) {
                $principal = round($this->principal / $this->termMonths, 2);
                $interest = 0.0;
            }

            if ($principal < 0) {
                $principal = 0.0;
            }

            $payment = $this->paymentPerMonth;
            if ($i === $this->termMonths) {
                $principal = round($balance, 2);
                $payment = round($principal + $interest, 2);
            }

            $balance = round(max(0.0, $balance - $principal), 2);

            $rows[] = [
                'installment_no' => $i,
                'due_date' => $dueDate,
                'installment_amount' => $payment,
                'principal_amount' => $principal,
                'interest_amount' => $interest,
            ];
        }

        return $rows;
    }

    private function solveMonthlyRate(float $principal, float $payment, int $termMonths): float
    {
        if ($principal <= 0 || $payment <= 0 || $termMonths <= 0) {
            return 0.0;
        }

        $minPaymentNoRate = $principal / $termMonths;
        if ($payment <= $minPaymentNoRate) {
            return 0.0;
        }

        $low = 0.0;
        $high = 1.0;

        for ($i = 0; $i < 100; $i++) {
            $mid = ($low + $high) / 2;
            $calcPmt = $this->paymentFromRate($principal, $mid, $termMonths);
            if ($calcPmt > $payment) {
                $high = $mid;
            } else {
                $low = $mid;
            }
        }

        return round(($low + $high) / 2, 12);
    }

    private function paymentFromRate(float $principal, float $monthlyRate, int $termMonths): float
    {
        if ($monthlyRate <= 0) {
            return $principal / $termMonths;
        }

        $pow = pow(1 + $monthlyRate, $termMonths);
        return $principal * $monthlyRate * $pow / ($pow - 1);
    }
}