/**
 * Looker Studio Community Connector for Advertising Management Platform
 * Official Multi-Channel Paid Ads Connector (Meta Ads, Google Ads, TikTok Ads)
 */

var cc = DataStudioApp.createCommunityConnector();

function getAuthType() {
  return cc.newAuthTypeResponse()
    .setAuthType(cc.AuthType.KEY)
    .setHelpUrl('https://adplatform.local/developer-settings')
    .build();
}

function isAuthValid() {
  var userProperties = PropertiesService.getUserProperties();
  var key = userProperties.getProperty('dscc.key');
  return !!key;
}

function setCredentials(request) {
  var key = request.key;
  var userProperties = PropertiesService.getUserProperties();
  userProperties.setProperty('dscc.key', key);
  return {
    errorCode: 'NONE'
  };
}

function resetAuth() {
  var userProperties = PropertiesService.getUserProperties();
  userProperties.deleteProperty('dscc.key');
}

function getConfig(request) {
  var config = cc.getConfig();

  config.newInfo()
    .setId('instructions')
    .setText('Configure your Advertising Management Platform reporting stream. Provide your API base URL if using a custom domain.');

  config.newTextInput()
    .setId('api_url')
    .setName('API Base URL')
    .setHelpText('e.g. https://api.yourdomain.com')
    .setPlaceholder('https://api.adplatform.local');

  config.setDateRangeRequired(true);

  return config.build();
}

function getFields() {
  var fields = cc.getFields();
  var types = cc.FieldType;
  var aggregations = cc.AggregationType;

  // Dimensions
  fields.newDimension()
    .setId('date')
    .setName('Date')
    .setType(types.YEAR_MONTH_DAY);

  fields.newDimension()
    .setId('provider')
    .setName('Advertising Platform')
    .setType(types.TEXT);

  fields.newDimension()
    .setId('account_name')
    .setName('Account Name')
    .setType(types.TEXT);

  fields.newDimension()
    .setId('campaign_name')
    .setName('Campaign Name')
    .setType(types.TEXT);

  fields.newDimension()
    .setId('currency')
    .setName('Currency')
    .setType(types.TEXT);

  // Metrics
  fields.newMetric()
    .setId('spend')
    .setName('Spend')
    .setType(types.CURRENCY_USD)
    .setAggregation(aggregations.SUM);

  fields.newMetric()
    .setId('impressions')
    .setName('Impressions')
    .setType(types.NUMBER)
    .setAggregation(aggregations.SUM);

  fields.newMetric()
    .setId('clicks')
    .setName('Clicks')
    .setType(types.NUMBER)
    .setAggregation(aggregations.SUM);

  fields.newMetric()
    .setId('conversions')
    .setName('Conversions')
    .setType(types.NUMBER)
    .setAggregation(aggregations.SUM);

  fields.newMetric()
    .setId('conversion_value')
    .setName('Conversion Value')
    .setType(types.CURRENCY_USD)
    .setAggregation(aggregations.SUM);

  fields.newMetric()
    .setId('cpc')
    .setName('Average CPC')
    .setType(types.CURRENCY_USD)
    .setAggregation(aggregations.AVG);

  fields.newMetric()
    .setId('roas')
    .setName('ROAS')
    .setType(types.NUMBER)
    .setAggregation(aggregations.AVG);

  return fields;
}

function getSchema(request) {
  var fields = getFields().build();
  return { schema: fields };
}

function getData(request) {
  var userProperties = PropertiesService.getUserProperties();
  var apiKey = userProperties.getProperty('dscc.key');
  var baseUrl = (request.configParams && request.configParams.api_url) 
    ? request.configParams.api_url 
    : 'http://localhost:8000';

  var startDate = request.dateRange.startDate; // YYYY-MM-DD
  var endDate = request.dateRange.endDate;

  var url = baseUrl + '/api/v1/reporting/looker-studio?start_date=' + startDate + '&end_date=' + endDate;

  var options = {
    method: 'get',
    headers: {
      'Authorization': 'Bearer ' + apiKey,
      'Accept': 'application/json'
    },
    muteHttpExceptions: true
  };

  var response = UrlFetchApp.fetch(url, options);
  var json = JSON.parse(response.getContentText());

  var requestedFieldIds = request.fields.map(function(f) { return f.name; });
  var schemaFields = json.schema.map(function(s) { return s.name; });

  var rows = [];
  (json.rows || []).forEach(function(row) {
    var values = [];
    requestedFieldIds.forEach(function(fieldId) {
      var index = schemaFields.indexOf(fieldId);
      if (index !== -1) {
        values.push(row.values[index]);
      } else {
        values.push(null);
      }
    });
    rows.push({ values: values });
  });

  return {
    schema: request.fields,
    rows: rows
  };
}
