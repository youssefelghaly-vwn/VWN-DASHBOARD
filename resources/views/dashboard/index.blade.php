<x-app-layout :rows="$rows" :headers="$headers" :chart-configs="$chartConfigs" title="Overview">

  <div class="topbar">
    <div>
      <h1>Overview</h1>
      <p>Leads, outreach, and booked appointments — read live from your Google Sheet.</p>
    </div>
    <div class="refresh-info">Last synced: <span id="last-sync">—</span><br>Auto-refresh every 30s</div>
  </div>

  @unless ($configured)
    <div class="insight" style="margin-bottom:24px;">
      <span class="ic">⚠️</span>
      <span>
        No Google Sheet is connected yet.
        @if (auth()->user()->is_admin)
          Head to <a href="{{ route('admin.google.index') }}"><b>Google &amp; Sheets</b></a> to connect one.
        @else
          Ask an admin to connect one under Google &amp; Sheets.
        @endif
      </span>
    </div>
  @endunless

  @if ($error)
    <div class="insight" style="margin-bottom:24px;border-left:3px solid var(--coral);">
      <span class="ic">⚠️</span>
      <span><b>Couldn't reach the sheet:</b> {{ $error }}</span>
    </div>
  @endif

  <div class="panel" style="margin-bottom:24px;">
    <div class="panel-head">
      <div>
        <h3>Data Source</h3>
        <p class="desc">Where this dashboard is reading from, right now.</p>
      </div>
      @if (auth()->user()->is_admin)
        <a href="{{ route('admin.google.index') }}" class="gear-btn" style="text-decoration:none;display:flex;align-items:center;justify-content:center;" title="Manage in Google &amp; Sheets">⚙</a>
      @endif
    </div>

    @if ($dataSource['project'])
      <div class="panel-stats" style="margin-bottom:14px;">
        <div class="panel-stat"><b>{{ $dataSource['project']['project_id'] }}</b>Google project</div>
        <div class="panel-stat"><b>{{ $dataSource['sheet_count'] }}</b>{{ Str::plural('sheet', $dataSource['sheet_count']) }} connected</div>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr>
            <th style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-soft);padding:6px 8px;border-bottom:1px solid var(--line);">Sheet</th>
            <th style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-soft);padding:6px 8px;border-bottom:1px solid var(--line);">Tab</th>
            <th style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-soft);padding:6px 8px;border-bottom:1px solid var(--line);">Columns</th>
            <th style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-soft);padding:6px 8px;border-bottom:1px solid var(--line);">Synced</th>
            <th style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-soft);padding:6px 8px;border-bottom:1px solid var(--line);"></th>
          </tr>
        </thead>
        <tbody>
          @foreach ($dataSource['sheets'] as $sheet)
            <tr>
              <td style="padding:8px;border-bottom:1px solid var(--panel-alt);font-weight:600;">{{ $sheet['name'] }}</td>
              <td style="padding:8px;border-bottom:1px solid var(--panel-alt);">{{ $sheet['tab_name'] ?? '—' }}</td>
              <td style="padding:8px;border-bottom:1px solid var(--panel-alt);font-family:'IBM Plex Mono',monospace;">{{ $sheet['column_count'] ?? '—' }}</td>
              <td style="padding:8px;border-bottom:1px solid var(--panel-alt);color:var(--ink-soft);">{{ $sheet['last_synced_at'] ?? 'never' }}</td>
              <td style="padding:8px;border-bottom:1px solid var(--panel-alt);">
                @if ($sheet['is_active'])
                  <span class="tag yes">Active</span>
                @else
                  <span class="tag status">Inactive</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @else
      <p style="font-size:12.5px;color:var(--ink-soft);">
        No Google project connected yet.
        @if (auth()->user()->is_admin)
          <a href="{{ route('admin.google.index') }}"><b>Connect one</b></a> to get started.
        @endif
      </p>
    @endif
  </div>

  <div class="kpis">
    <div class="kpi accent">
      <div class="label">Total Leads</div>
      <div class="value" id="kpi-leads">0</div>
      <div class="sub" id="kpi-leads-sub">&nbsp;</div>
    </div>
    <div class="kpi">
      <div class="label">Onboarding Msgs Sent</div>
      <div class="value" id="kpi-msgs">0</div>
      <div class="sub" id="kpi-msgs-sub">&nbsp;</div>
    </div>
    <div class="kpi">
      <div class="label">Appointments Booked</div>
      <div class="value" id="kpi-booked">0</div>
      <div class="sub" id="kpi-booked-sub">&nbsp;</div>
    </div>
    <div class="kpi">
      <div class="label">Converted</div>
      <div class="value" id="kpi-converted">0</div>
      <div class="sub" id="kpi-converted-sub">&nbsp;</div>
    </div>
    <div class="kpi">
      <div class="label">Conversion Rate</div>
      <div class="value" id="kpi-conv">0%</div>
      <div class="sub">converted ÷ messaged</div>
    </div>
    <div class="kpi">
      <div class="label">Active SDRs</div>
      <div class="value" id="kpi-sdrs">0</div>
      <div class="sub">across pipeline</div>
    </div>
  </div>

  <div class="panel" style="margin-bottom:16px;">
    <div class="panel-head">
      <div>
        <h3>The Trail — Lead → Message → Booked → Converted</h3>
        <p class="desc">How the pipeline narrows at each stage. Also controls the KPI tiles above.</p>
      </div>
      <button type="button" class="gear-btn" data-chart-gear="funnel" title="Configure pipeline fields">⚙</button>
    </div>
    <div class="funnel-wrap" id="funnel-wrap"></div>
  </div>

  <div class="grid-2">
    <x-chart-panel chart-key="sdr_conversion" title="Conversion Rate by SDR" description="Converted appointments as a share of messages sent, per rep." />
    <x-chart-panel chart-key="relationship_status" title="LinkedIn Relationship Status" description="Where leads sit in the connection pipeline." />
  </div>

  <div class="section-head">
    <div>
      <div class="eyebrow">Company Intelligence</div>
      <h2>Roles, Salary &amp; Employment Type</h2>
      <p>What kinds of jobs and companies make up this pipeline, and what they pay.</p>
    </div>
  </div>

  <div class="grid-2">
    <x-chart-panel chart-key="leads_by_industry" title="Leads by Industry" description="Top sectors represented in this pipeline." />
    <x-chart-panel chart-key="leads_by_location" title="Leads by Location" description="Geographic spread of sourced companies." />
  </div>

  <div class="grid-2">
    <x-chart-panel chart-key="top_job_titles" title="Top Job Titles" description="Most frequently sourced roles across all companies." />
    <x-chart-panel chart-key="employment_type" title="Employment Type" description="Full-time vs. part-time vs. contract split." />
  </div>

  <div class="grid-2">
    <x-chart-panel chart-key="seniority_level" title="Seniority Level" description="Distribution of experience level requested." />

    <x-chart-panel chart-key="salary_range" title="Salary Range by Job Title" description="Min–max posted range, top 8 roles by lead volume.">
      <x-slot:before>
        <div class="panel-stats">
          <div class="panel-stat"><b id="stat-avg-salary">—</b>avg. midpoint</div>
          <div class="panel-stat"><b id="stat-max-salary">—</b>highest posted</div>
          <div class="panel-stat"><b id="stat-salary-coverage">—</b>leads with salary data</div>
        </div>
      </x-slot:before>
    </x-chart-panel>
  </div>

  <div class="section-head">
    <div>
      <div class="eyebrow">Pipeline Analysis</div>
      <h2>Messaged → Booked Relationship</h2>
      <p>How outreach volume actually converts into booked appointments, segmented by industry and rep.</p>
    </div>
  </div>

  <div class="grid-2">
    <x-chart-panel chart-key="msg_booked_industry" title="Messaged vs. Booked by Industry" description="Raw volume comparison — where outreach is heavy but booking is thin (or vice versa)." />

    <x-chart-panel chart-key="correlation" title="Messaged–Booked Correlation" description="Each bubble is an industry segment. Size = total leads in that segment.">
      <div class="bubble-legend"><span><span class="sw"></span>Bubble size = lead count</span></div>
      <div class="insight" id="correlation-insight"></div>
    </x-chart-panel>
  </div>

  <x-chart-panel chart-key="sdr_efficiency" title="SDR Efficiency — Messaged vs. Booked" description="Direct rep-by-rep comparison of activity volume against outcomes." style="margin-bottom:16px;" />

  <div class="panel table-panel">
    <h3>All Leads</h3>
    <p class="desc">Full pipeline detail — search, filter, and drill in.</p>
    <div class="table-controls">
      <input type="text" id="search-input" placeholder="Search company, job, location…">
      <select id="filter-sdr"><option value="">All SDRs</option></select>
      <select id="filter-status"><option value="">All statuses</option></select>
    </div>
    <div class="table-scroll">
      <table>
        <thead>
          <tr>
            <th>Company</th>
            <th>Industry</th>
            <th>Location</th>
            <th>SDR</th>
            <th>Relationship</th>
            <th>Msg Sent</th>
            <th>Booked</th>
            <th>Converted</th>
            <th>Conv. Rate</th>
          </tr>
        </thead>
        <tbody id="table-body"></tbody>
      </table>
    </div>
  </div>

</x-app-layout>