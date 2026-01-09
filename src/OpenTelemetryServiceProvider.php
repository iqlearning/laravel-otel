<?php

namespace Iqlearning\LaravelOtel;

use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Zipkin\Exporter as ZipkinExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Sdk;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/opentelemetry.php',
            'opentelemetry'
        );

        // Register tracer interface
        $this->app->singleton(TracerInterface::class, function ($app) {
            return $this->createTracer();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/opentelemetry.php' => config_path('opentelemetry.php'),
        ], 'laravel-otel-config');

        // Register global tracer provider using SDK builder
        $tracerProvider = $this->createTracerProvider();

        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();
    }

    /**
     * Create the TracerProvider with configured exporters
     */
    private function createTracerProvider(): TracerProvider
    {
        $resource = $this->createResource();
        $sampler = $this->createSampler();

        $tracerProvider = TracerProvider::builder()
            ->setResource($resource)
            ->setSampler($sampler);

        // Add exporters based on configuration
        $exporterType = config('opentelemetry.exporter.type');

        if ($exporterType === 'jaeger' || $exporterType === 'both') {
            if (config('opentelemetry.exporter.jaeger.enabled')) {
                $jaegerExporter = $this->createJaegerExporter();
                $tracerProvider->addSpanProcessor(
                    new BatchSpanProcessor($jaegerExporter, ClockFactory::getDefault())
                );
            }
        }

        if ($exporterType === 'zipkin' || $exporterType === 'both') {
            if (config('opentelemetry.exporter.zipkin.enabled')) {
                $zipkinExporter = $this->createZipkinExporter();
                $tracerProvider->addSpanProcessor(
                    new BatchSpanProcessor($zipkinExporter, ClockFactory::getDefault())
                );
            }
        }

        return $tracerProvider->build();
    }

    /**
     * Create Jaeger exporter using OTLP protocol
     */
    private function createJaegerExporter(): SpanExporter
    {
        $endpoint = config('opentelemetry.exporter.jaeger.endpoint');

        return new SpanExporter(
            PsrTransportFactory::discover()->create($endpoint, 'application/json')
        );
    }

    /**
     * Create Zipkin exporter
     */
    private function createZipkinExporter(): ZipkinExporter
    {
        $endpoint = config('opentelemetry.exporter.zipkin.endpoint');

        return new ZipkinExporter(
            PsrTransportFactory::discover()->create($endpoint, 'application/json')
        );
    }

    /**
     * Create resource with service information
     */
    private function createResource(): ResourceInfo
    {
        $attributes = array_merge(
            [
                'service.name' => config('opentelemetry.service_name'),
                'service.version' => config('opentelemetry.service_version'),
            ],
            config('opentelemetry.resource_attributes', [])
        );

        return ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create($attributes))
        );
    }

    /**
     * Create sampler based on configuration
     */
    private function createSampler()
    {
        $samplerType = config('opentelemetry.traces.sampler');

        return match ($samplerType) {
            'always_on' => new AlwaysOnSampler(),
            'always_off' => new AlwaysOffSampler(),
            'traceidratio' => new TraceIdRatioBasedSampler(
                config('opentelemetry.traces.sampler_arg', 1.0)
            ),
            default => new AlwaysOnSampler(),
        };
    }

    /**
     * Create tracer instance
     */
    private function createTracer(): TracerInterface
    {
        return $this->createTracerProvider()->getTracer(
            config('opentelemetry.service_name'),
            config('opentelemetry.service_version')
        );
    }
}

