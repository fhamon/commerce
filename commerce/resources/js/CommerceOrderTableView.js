/**
 * Class Craft.CommerceOrderTableView
 */
Craft.CommerceOrderTableView = Craft.TableElementIndexView.extend({

    chartToggleState: null,
    dateRangeState: null,

    startDate: null,
    endDate: null,

	$chartExplorer: null,

	afterInit: function()
    {
        this.chartToggleState = Craft.getLocalStorage('CommerceOrdersIndex.chartToggleState', false);
        this.dateRangeState = Craft.getLocalStorage('CommerceOrdersIndex.dateRangeState', 'd7');

        this.startDate = new Date();
        this.startDate.setDate(this.startDate.getDate() - 7);
        this.endDate = new Date();

        var $viewBtns = $('.viewbtns');
        $viewBtns.removeClass('hidden');

        this.$explorerContainer = $('<div class="chart-explorer-container"></div>').prependTo(this.$container);

        if($('.chart-toggle', $viewBtns).length == 0)
        {
            var $chartToggleContainer = $('<div class="chart-toggle-container"></div>').appendTo($viewBtns);
            var $chartToggle = $('<a class="btn chart-toggle" data-icon="area"></a>').appendTo($chartToggleContainer);
        }
        else
        {
            var $chartToggleContainer = $('.chart-toggle-container', $viewBtns);
            var $chartToggle = $('.chart-toggle', $chartToggleContainer);
        }

        this.addListener($chartToggle, 'click', 'toggleChartExplorer');

        if(this.chartToggleState)
        {
            $chartToggle.trigger('click');
        }

		this.base();
	},

    toggleChartExplorer: function(ev)
    {
        var $chartToggle = $(ev.currentTarget);

        if(this.$chartExplorer)
        {
            this.$chartExplorer.toggleClass('hidden');
        }
        else
        {
            this.createChartExplorer();
        }

        this.chartToggleState = false;

        if(!this.$chartExplorer.hasClass('hidden'))
        {
            this.chartToggleState = true;
        }

        if(this.chartToggleState == true)
        {
            $chartToggle.addClass('active');
        }
        else
        {
            $chartToggle.removeClass('active');
        }

        Craft.setLocalStorage('CommerceOrdersIndex.chartToggleState', this.chartToggleState);
    },

    createChartExplorer: function()
    {
        var $chartExplorer = $('<div class="chart-explorer"></div>').appendTo(this.$explorerContainer),
            $chartHeader = $('<div class="chart-header"></div>').appendTo($chartExplorer),
            $dateRangeContainer = $('<div class="datewrapper" />').appendTo($chartHeader),
            $total = $('<div class="total"><strong>Total Revenue</strong></div>').appendTo($chartHeader),
            $totalCountWrapper = $('<div class="count-wrapper light"></div>').appendTo($total);

        this.$chartExplorer = $chartExplorer;
        this.$error = $('<div class="error">Example error.</div>').appendTo($chartHeader);
        this.$spinner = $('<div class="spinner hidden" />').appendTo($chartHeader);
        this.$totalCount = $('<span class="count">0</span>').appendTo($totalCountWrapper);
        this.$chartContainer = $('<div class="chart-container"></div>').appendTo($chartExplorer);
        this.$dateRange = $('<input type="text" class="text" />').appendTo($dateRangeContainer);

        this.dateRange = new Craft.DateRangePicker(this.$dateRange, {
            value: this.dateRangeState,
            onAfterSelect: $.proxy(this, 'onAfterDateRangeSelect')
        });

        this.loadReport(this.dateRange.startDate, this.dateRange.endDate);
    },

    onAfterDateRangeSelect: function(value, startDate, endDate)
    {
        Craft.setLocalStorage('CommerceOrdersIndex.dateRangeState', value);

        this.loadReport(startDate, endDate)
    },

    loadReport: function(startDate, endDate)
    {
        var requestData = this.settings.params;

        requestData.startDate = startDate;
        requestData.endDate = endDate;

        this.$spinner.removeClass('hidden');
        this.$error.addClass('hidden');
        this.$chartContainer.removeClass('error');

        Craft.postActionRequest('commerce/reports/getRevenueReport', requestData, $.proxy(function(response, textStatus)
        {
            this.$spinner.addClass('hidden');

            if(textStatus == 'success' && typeof(response.error) == 'undefined')
            {
                if(!this.chart)
                {
                    this.chart = new Craft.charts.Area(this.$chartContainer);
                }

                var chartDataTable = new Craft.charts.DataTable(response.reportDataTable);

                var chartSettings = {
                    currency: response.currencyFormat,
                    dataScale: response.scale
                };

                this.chart.draw(chartDataTable, chartSettings);

                this.$totalCount.html(response.totalHtml);

            }
            else
            {
                var msg = 'An unknown error occured.';

                if(typeof(response) != 'undefined' && response && typeof(response.error) != 'undefined')
                {
                    msg = response.error;
                }

                this.$error.html(msg);
                this.$error.removeClass('hidden');
                this.$chartContainer.addClass('error');
            }

        }, this));
    }
});