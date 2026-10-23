import { useEffect, useState } from 'react';
import { api, submitForm } from './api.js';

const residentLinks = [
  ['/resident', 'Overview'],
  ['/resident/requests', 'Document requests'],
  ['/resident/incidents', 'Incident reports'],
  ['/resident/profile', 'My profile']
];
const staffLinks = [
  ['/staff', 'Overview'],
  ['/staff/requests', 'Requests'],
  ['/staff/incidents', 'Incidents'],
  ['/staff/hearings', 'Hearings'],
  ['/staff/residents', 'Residents'],
  ['/staff/reports', 'Reports']
];

function money(value) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0));
}

function date(value) {
  if (!value) return '—';
  const parsed = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
}

function Link({ href, go, children, className = '' }) {
  return <a className={className} href={href} onClick={(event) => {
    if (event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) {
      event.preventDefault();
      go(href);
    }
  }}>{children}</a>;
}

function Field({ label, children, hint }) {
  return <label className="field"><span>{label}</span>{children}{hint && <small>{hint}</small>}</label>;
}

function Text({ label, name, type = 'text', required = true, defaultValue, placeholder, hint, readOnly, step, min }) {
  return <Field label={label} hint={hint}><input name={name} type={type} required={required} minLength={type === 'password' ? 12 : undefined} maxLength={type === 'password' ? 72 : undefined} defaultValue={defaultValue ?? ''} placeholder={placeholder} readOnly={readOnly} step={step} min={min} /></Field>;
}

function Select({ label, name, items, defaultValue, required = true }) {
  return <Field label={label}><select name={name} defaultValue={defaultValue ?? ''} required={required}>
    <option value="" disabled>Select an option</option>
    {items.map((item) => <option key={item.value ?? item.id} value={item.value ?? item.id}>{item.label ?? item.name}</option>)}
  </select></Field>;
}

function Textarea({ label, name, required = true, defaultValue, rows = 3 }) {
  return <Field label={label}><textarea name={name} required={required} defaultValue={defaultValue ?? ''} rows={rows} /></Field>;
}

function Button({ children, disabled, variant = '' }) {
  return <button className={`button ${variant}`} type="submit" disabled={disabled}>{children}</button>;
}

function Empty({ children = 'Nothing to show yet.' }) {
  return <div className="empty">{children}</div>;
}

function DataTable({ columns, rows }) {
  if (!rows?.length) return <Empty />;
  return <div className="table-wrap"><table><thead><tr>{columns.map((col) => <th key={col.label}>{col.label}</th>)}</tr></thead>
    <tbody>{rows.map((row, index) => <tr key={row.id ?? row.reference_no ?? index}>{columns.map((col) =>
      <td key={col.label}>{col.render ? col.render(row) : row[col.key] ?? '—'}</td>
    )}</tr>)}</tbody></table></div>;
}

function Status({ children }) {
  const tone = String(children || '').toLowerCase().replaceAll(' ', '-');
  return <span className={`status status-${tone}`}>{children || 'Unknown'}</span>;
}

export default function App() {
  const [path, setPath] = useState(window.location.pathname);
  const [user, setUser] = useState(null);
  const [sessionReady, setSessionReady] = useState(false);
  const [pageData, setPageData] = useState(null);
  const [publicData, setPublicData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  function go(nextPath) {
    if (nextPath !== window.location.pathname) {
      window.history.pushState({}, '', nextPath);
      setPath(nextPath);
    }
    setError('');
    setNotice('');
  }

  useEffect(() => {
    const onPopState = () => setPath(window.location.pathname);
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, []);

  useEffect(() => {
    api('/api/me')
      .then((result) => setUser(result.user))
      .catch((failure) => setError(failure.message))
      .finally(() => setSessionReady(true));
  }, []);

  useEffect(() => {
    if (!sessionReady) return;
    const page = path.replace(/\/+$/, '') || '/';
    setError('');
    if ((page.startsWith('/resident') && user?.role !== 'user')
      || (page.startsWith('/staff') && !['admin', 'moderator'].includes(user?.role))
      || (page.startsWith('/admin') && user?.role !== 'admin')) {
      setPageData(null);
      setLoading(false);
      return;
    }
    const endpoints = {
      '/services': '/api/public',
      '/resident': '/api/resident/dashboard',
      '/resident/requests': '/api/resident/requests',
      '/resident/requests/new': '/api/resident/requests',
      '/resident/incidents': '/api/resident/incidents',
      '/resident/incidents/new': '/api/resident/incidents',
      '/resident/profile': '/api/resident/profile',
      '/staff': '/api/staff/dashboard',
      '/staff/requests': '/api/staff/requests',
      '/staff/incidents': '/api/staff/incidents',
      '/staff/hearings': '/api/staff/hearings',
      '/staff/residents': '/api/staff/residents',
      '/staff/reports': '/api/staff/reports',
      '/admin/users': '/api/admin/users',
      '/admin/settings': '/api/admin/settings'
    };
    if (!endpoints[page]) {
      setPageData(null);
      setLoading(false);
      return;
    }
    setLoading(true);
    setError('');
    api(endpoints[page])
      .then((data) => {
        setPageData(data);
        if (page === '/services') setPublicData(data);
      })
      .catch((failure) => {
        setPageData(null);
        setError(failure.message);
      })
      .finally(() => setLoading(false));
  }, [path, sessionReady, user?.id]);

  async function action(callback, successMessage) {
    setSaving(true);
    setError('');
    setNotice('');
    try {
      const result = await callback();
      if (successMessage) setNotice(result?.message || successMessage);
      return result;
    } catch (failure) {
      setError(failure.message);
      return null;
    } finally {
      setSaving(false);
    }
  }

  function roleHome(account = user) {
    if (account?.role === 'admin') return '/admin/users';
    if (account?.role === 'moderator') return '/staff';
    return '/resident';
  }

  async function handleForm(event, endpoint, onSuccess, message) {
    event.preventDefault();
    const form = event.currentTarget;
    const result = await action(() => submitForm(endpoint, form), message);
    if (result) {
      onSuccess?.(result);
    }
  }

  const isStaff = user && ['admin', 'moderator'].includes(user.role);
  const navLinks = user?.role === 'admin'
    ? [['/admin/users', 'Users'], ['/admin/settings', 'Settings'], ...staffLinks.slice(1)]
    : user?.role === 'moderator' ? staffLinks : user ? residentLinks : [['/', 'Home'], ['/services', 'Services'], ['/track', 'Track a request']];

  let content;
  if (!sessionReady || loading) {
    content = <div className="loading"><span className="spinner" />Loading BarangayConnect…</div>;
  } else if (path === '/login') {
    content = <AuthPage kind="login" go={go} saving={saving} onSubmit={(event) => handleForm(event, '/api/login', (result) => {
      setUser(result.user); go(roleHome(result.user));
    }, 'Signed in successfully.')} />;
  } else if (path === '/register') {
    content = <AuthPage kind="register" go={go} saving={saving} onSubmit={(event) => handleForm(event, '/api/register', (result) => {
      setUser(result.user); go('/resident');
    }, 'Your account is ready.')} />;
  } else if (path === '/setup') {
    content = <SetupPage go={go} saving={saving} onSubmit={(event) => handleForm(event, '/api/setup', () => go('/login'), 'Administrator created. Sign in with the new account.')} />;
  } else if (path === '/track') {
    content = <TrackPage go={go} />;
  } else if ((path.startsWith('/resident') && user?.role !== 'user') || (path.startsWith('/staff') && !isStaff) || (path.startsWith('/admin') && user?.role !== 'admin')) {
    content = <section className="panel narrow"><p className="eyebrow">ACCOUNT ACCESS</p><h1>Sign in to continue</h1><p>This part of BarangayConnect is available after you sign in with an authorized account.</p><Link className="button" href="/login" go={go}>Go to sign in</Link></section>;
  } else if (path === '/' || path === '/services' || path === '/about') {
    content = <HomePage data={publicData} error={error} go={go} servicesOnly={path === '/services'} user={user} />;
  } else if (path === '/resident') {
    content = <ResidentDashboard data={pageData} go={go} />;
  } else if (path === '/resident/requests' || path === '/resident/requests/new') {
    content = <ResidentRequests data={pageData} go={go} saving={saving} onSubmit={(event) => handleForm(event, '/api/resident/requests', () => api('/api/resident/requests').then(setPageData).catch((failure) => setError(failure.message)), 'Request submitted.')} onPay={(id) => action(() => api(`/api/resident/requests/${id}/payment`, { method: 'POST' })).then((result) => {
      if (result?.checkout_url) window.location.assign(result.checkout_url);
    })} />;
  } else if (path === '/resident/incidents' || path === '/resident/incidents/new') {
    content = <ResidentIncidents data={pageData} go={go} saving={saving} onSubmit={(event) => handleForm(event, '/api/resident/incidents', () => api('/api/resident/incidents').then(setPageData).catch((failure) => setError(failure.message)), 'Incident report submitted.')} />;
  } else if (path === '/resident/profile') {
    content = <ProfilePage data={pageData} user={user} saving={saving} onSubmit={(event) => handleForm(event, '/api/resident/profile', (result) => {
      Promise.all([api('/api/me'), api('/api/resident/profile')]).then(([session, profile]) => {
        setUser(session.user);
        setPageData(profile);
      }).catch((failure) => setError(failure.message));
    }, 'Profile updated.')} />;
  } else if (path === '/staff') {
    content = <StaffDashboard data={pageData} go={go} />;
  } else if (path === '/staff/requests') {
    content = <StaffRequests data={pageData} saving={saving} onUpdate={(id, form) => action(() => submitForm(`/api/staff/requests/${id}`, form), 'Request updated.').then(() => api('/api/staff/requests').then(setPageData).catch((failure) => setError(failure.message)))} />;
  } else if (path === '/staff/incidents') {
    content = <StaffIncidents data={pageData} saving={saving} onUpdate={(id, form) => action(() => submitForm(`/api/staff/incidents/${id}`, form), 'Incident case updated.').then(() => api('/api/staff/incidents').then(setPageData).catch((failure) => setError(failure.message)))} />;
  } else if (path === '/staff/hearings') {
    content = <StaffHearings data={pageData} saving={saving} onCreate={(event) => handleForm(event, '/api/staff/hearings', () => api('/api/staff/hearings').then(setPageData), 'Hearing scheduled.')} onUpdate={(id, form) => action(() => submitForm(`/api/staff/hearings/${id}`, form), 'Hearing updated.').then(() => api('/api/staff/hearings').then(setPageData).catch((failure) => setError(failure.message)))} />;
  } else if (path === '/staff/residents') {
    content = <ResidentsPage data={pageData} onSearch={(query) => action(() => api(`/api/staff/residents?q=${encodeURIComponent(query)}`)).then((result) => result && setPageData(result))} />;
  } else if (path === '/staff/reports') {
    content = <ReportsPage data={pageData} />;
  } else if (path === '/admin/users') {
    content = <AdminUsers data={pageData} user={user} saving={saving} onCreate={(event) => handleForm(event, '/api/admin/users', () => api('/api/admin/users').then(setPageData), 'Staff account created.')} onStatus={(id, active) => action(() => api(`/api/admin/users/${id}/status`, { method: 'POST', body: { is_active: active } }), 'Account status updated.').then(() => api('/api/admin/users').then(setPageData).catch((failure) => setError(failure.message)))} />;
  } else if (path === '/admin/settings') {
    content = <AdminSettings data={pageData} saving={saving} onSave={(event) => handleForm(event, '/api/admin/settings', () => api('/api/admin/settings').then(setPageData), 'Settings saved.')} onService={(event, id) => handleForm(event, id ? `/api/admin/services/${id}` : '/api/admin/services', () => api('/api/admin/settings').then(setPageData), 'Document service saved.')} onIncidentType={(event) => handleForm(event, '/api/admin/incident-types', () => api('/api/admin/settings').then(setPageData), 'Incident type added.')} />;
  } else {
    content = <section className="panel"><h1>Page not found</h1><Link href="/" go={go}>Return home</Link></section>;
  }

  return <div className="app-shell">
    <header className="topbar">
      <Link href="/" go={go} className="brand"><span className="brand-mark">B</span><span>Barangay<span className="brand-light">Connect</span><small>LOCAL SERVICES, MADE SIMPLE</small></span></Link>
      <nav className="main-nav" aria-label="Main navigation">
        {navLinks.map(([href, label]) => <Link key={href} href={href} go={go} className={path === href ? 'active' : ''}>{label}</Link>)}
      </nav>
      <div className="account">
        {user ? <><span className="account-name">{user.full_name}</span><button className="text-button" onClick={() => action(() => api('/api/logout', { method: 'POST' }), 'Signed out.').then((result) => {
          if (result) { setUser(null); go('/'); }
        })}>Sign out</button></> : <><Link href="/login" go={go}>Sign in</Link><Link className="button button-small" href="/register" go={go}>Create account</Link></>}
      </div>
    </header>
    <main className="main-content">
      {error && <div className="alert alert-error" role="alert">{error}<button aria-label="Dismiss message" onClick={() => setError('')}>×</button></div>}
      {notice && <div className="alert alert-success" role="status">{notice}<button aria-label="Dismiss message" onClick={() => setNotice('')}>×</button></div>}
      {content}
    </main>
    <footer className="footer"><span>BarangayConnect</span><span>Secure local services for every resident.</span></footer>
  </div>;
}

function AuthPage({ kind, go, saving, onSubmit }) {
  const register = kind === 'register';
  return <section className="auth-layout">
    <div className="auth-aside"><p className="eyebrow">YOUR BARANGAY, WITH YOU</p><h1>{register ? 'A simpler way to get things done.' : 'Welcome back.'}</h1><p>Request documents, track updates, and connect with your barangay office securely.</p><div className="aside-note"><b>Private by design</b><span>Your personal documents are stored securely and shared only with authorized staff.</span></div></div>
    <div className="panel auth-panel"><p className="eyebrow">{register ? 'RESIDENT ACCOUNT' : 'SECURE SIGN IN'}</p><h2>{register ? 'Create your account' : 'Sign in'}</h2>
      <form className="form-grid" onSubmit={onSubmit}>
        {register && <><Text label="Full name" name="full_name" /><Text label="Contact number" name="contact" type="tel" /><Text label="Purok / street address" name="address" /><label className="check"><input name="sms_opt_in" type="checkbox" value="1" /><span>Send me SMS updates about request status</span></label></>}
        <Text label="Email address" name="email" type="email" />
        <Text label="Password" name="password" type="password" hint={register ? 'Use 12–72 characters.' : undefined} />
        <Button disabled={saving}>{saving ? 'Please wait…' : register ? 'Create resident account' : 'Sign in securely'}</Button>
      </form>
      <div className="form-foot">{register ? <>Already registered? <Link href="/login" go={go}>Sign in</Link></> : <>New to the portal? <Link href="/register" go={go}>Create an account</Link></>}</div>
      {!register && <div className="form-foot"><a href="/auth/google">Continue with Google</a></div>}
    </div>
  </section>;
}

function SetupPage({ go, saving, onSubmit }) {
  return <section className="panel narrow"><p className="eyebrow">ONE-TIME SETUP</p><h1>Create the first administrator</h1><p>Use the setup token stored in your local <code>.env</code> file. This page is disabled after an administrator is created.</p>
    <form className="form-grid" onSubmit={onSubmit}><Text label="Setup token" name="setup_token" type="password" /><Text label="Administrator name" name="full_name" /><Text label="Email address" name="email" type="email" /><Text label="Password" name="password" type="password" hint="Use 12–72 characters." /><Button disabled={saving}>Create administrator</Button></form>
    <p className="form-foot"><Link href="/login" go={go}>Return to sign in</Link></p>
  </section>;
}

function HomePage({ data, error, go, servicesOnly, user }) {
  const services = data?.services || [];
  const dashboardPath = user?.role === 'admin' ? '/admin/users' : user?.role === 'moderator' ? '/staff' : '/resident';
  if (servicesOnly) return <section><PageHeading eyebrow="SERVICES" title="Barangay services" intro="Explore available documents and the information you need before applying." />
    {services.length ? <div className="service-grid">{services.map((service) => <article className="service-card" key={service.id}><span className="card-icon">▤</span><h3>{service.name}</h3><p>{service.description}</p><div className="service-bottom"><b>{money(service.fee)}</b><Link href="/resident/requests" go={go}>Request service →</Link></div></article>)}</div> : <Empty>{error || 'Service list will appear after database setup.'}</Empty>}
  </section>;
  return <><section className="hero">
    <div className="hero-copy"><p className="eyebrow">WELCOME{data?.barangay?.name ? ` TO ${data.barangay.name.toUpperCase()}` : ' TO YOUR COMMUNITY PORTAL'}</p><h1>Local services.<br /><span>Closer to home.</span></h1><p>Request essential documents, report community concerns, and stay updated with a secure portal built for your barangay.</p><div className="hero-actions"><Link className="button" href={user ? dashboardPath : '/register'} go={go}>{user ? 'Open dashboard' : 'Get started'} <span>→</span></Link><Link className="button button-outline" href="/services" go={go}>Explore services</Link></div><div className="hero-meta"><span><i /> Secure resident portal</span><span><i /> Updates in one place</span></div></div>
    <div className="hero-art"><div className="art-circle circle-one" /><div className="art-circle circle-two" /><div className="art-building"><div className="building-roof" /><div className="building-sign">BARANGAY<br />HALL</div><div className="building-columns"><i /><i /><i /></div><div className="building-base" /></div><div className="art-badge"><span>✦</span><b>Here for<br />your community</b></div></div>
  </section><section className="section-block"><PageHeading eyebrow="WHAT YOU CAN DO" title="Everyday services, made easier." intro="Spend less time waiting in line and more time focusing on what matters." />
    <div className="feature-grid"><Feature icon="▤" title="Request documents" text="Apply for clearances and certificates online, with secure document handling." /><Feature icon="⌖" title="Report a concern" text="Send an incident report to authorized staff and follow its progress." /><Feature icon="↗" title="Stay informed" text="See request updates, payment status, and hearing schedules in your account." /></div>
  </section><section className="cta"><div><p className="eyebrow">READY WHEN YOU ARE</p><h2>Start with a resident account.</h2><p>Registration is free. Your information stays protected.</p></div><Link className="button button-light" href="/register" go={go}>Create an account <span>→</span></Link></section></>;
}

function Feature({ icon, title, text }) {
  return <article className="feature-card"><span className="feature-icon">{icon}</span><h3>{title}</h3><p>{text}</p></article>;
}

function PageHeading({ eyebrow, title, intro, action }) {
  return <div className="page-heading"><div><p className="eyebrow">{eyebrow}</p><h1>{title}</h1>{intro && <p className="page-intro">{intro}</p>}</div>{action}</div>;
}

function StatCard({ label, value, detail, icon }) {
  return <article className="stat-card"><div className="stat-top"><span>{label}</span><b>{icon || '✦'}</b></div><strong>{value ?? 0}</strong>{detail && <small>{detail}</small>}</article>;
}

function ResidentDashboard({ data, go }) {
  return <><PageHeading eyebrow="RESIDENT PORTAL" title="Your dashboard" intro="A quick look at your documents, reports, and latest updates." action={<Link className="button" href="/resident/requests" go={go}>New document request +</Link>} />
    <div className="stats-grid"><StatCard label="In progress" value={data?.pending_requests} detail="Document requests" icon="◷" /><StatCard label="Ready for pickup" value={data?.ready_requests} detail="Approved documents" icon="✓" /><StatCard label="My reports" value={data?.incidents} detail="Incident reports filed" icon="⌖" /></div>
    <section className="panel"><div className="panel-heading"><div><p className="eyebrow">RECENT ACTIVITY</p><h2>Notifications</h2></div></div>{data?.notifications?.length ? <div className="activity-list">{data.notifications.map((item, index) => <div className="activity-item" key={`${item.created_at}-${index}`}><span className="activity-dot" /><div><p>{item.message}</p><small>{date(item.created_at)} · {item.channel} · {item.status}</small></div></div>)}</div> : <Empty>No updates yet. Your request updates will show here.</Empty>}</section>
  </>;
}

function ResidentRequests({ data, go, saving, onSubmit, onPay }) {
  return <><PageHeading eyebrow="RESIDENT SERVICES" title="Document requests" intro="Apply for barangay documents and monitor their progress." action={<a className="button" href="#new-request">New request +</a>} />
    <section className="panel"><div className="panel-heading"><div><p className="eyebrow">YOUR APPLICATIONS</p><h2>Request history</h2></div></div><DataTable rows={data?.requests} columns={[
      { label: 'Reference', key: 'reference_no' }, { label: 'Document', key: 'service_name' }, { label: 'Submitted', render: (r) => date(r.created_at) },
      { label: 'Fee', render: (r) => money(r.fee) }, { label: 'Payment', render: (r) => <Status>{r.payment_status}</Status> },
      { label: 'Status', render: (r) => <Status>{r.status}</Status> },
      { label: 'Action', render: (r) => Number(r.fee) > 0 && r.payment_status !== 'Paid' && r.status !== 'Rejected' ? <button className="button button-small" type="button" onClick={() => onPay(r.id)}>Pay online</button> : '—' }
    ]} /></section>
    <section className="panel form-panel" id="new-request"><div className="panel-heading"><div><p className="eyebrow">NEW APPLICATION</p><h2>Request a document</h2><p>Upload a valid ID (PDF, JPEG, or PNG, up to 5 MB).</p></div></div>
      <form className="form-grid two-col" onSubmit={onSubmit}><Select label="Document type" name="service_type_id" items={data?.services || []} /><Text label="Purpose" name="purpose" /><Field label="Valid ID file"><input type="file" name="valid_id" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required /></Field><Button disabled={saving}>{saving ? 'Submitting…' : 'Submit request'}</Button></form>
    </section>
  </>;
}

function ResidentIncidents({ data, go, saving, onSubmit }) {
  const now = new Date();
  const today = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  return <><PageHeading eyebrow="RESIDENT SUPPORT" title="Incident reports" intro="File a community concern privately. Authorized barangay staff can review your report." />
    <section className="panel"><div className="panel-heading"><div><p className="eyebrow">YOUR REPORTS</p><h2>Report history</h2></div></div><DataTable rows={data?.incidents} columns={[
      { label: 'Reference', key: 'reference_no' }, { label: 'Incident', key: 'incident_type' }, { label: 'Date', key: 'incident_date' }, { label: 'Location', key: 'location' },
      { label: 'Next hearing', render: (r) => date(r.next_hearing) }, { label: 'Status', render: (r) => <Status>{r.status}</Status> }, { label: 'Staff notes', key: 'staff_remarks' }
    ]} /></section>
    <section className="panel form-panel"><div className="panel-heading"><div><p className="eyebrow">FILE A REPORT</p><h2>Incident details</h2></div></div>
      <form className="form-grid two-col" onSubmit={onSubmit}><Select label="Incident type" name="incident_type_id" items={data?.types || []} /><Text label="Date of incident" name="incident_date" type="date" hint="Today or a past date." /><Text label="Location" name="location" /><Field label="Evidence (optional)"><input type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" /><small>PDF, JPEG, or PNG, up to 5 MB.</small></Field><Text label="Latitude (optional)" name="latitude" required={false} /><Text label="Longitude (optional)" name="longitude" required={false} /><div className="span-two"><Textarea label="What happened?" name="description" rows={5} /></div><Button disabled={saving}>{saving ? 'Submitting…' : 'Submit incident report'}</Button></form>
    </section>
  </>;
}

function ProfilePage({ data, user, saving, onSubmit }) {
  const profile = data?.user || user || {};
  return <><PageHeading eyebrow="ACCOUNT" title="My profile" intro="Keep your contact information current so the barangay office can reach you." />
    <section className="panel narrow"><form className="form-grid" onSubmit={onSubmit}><Text label="Full name" name="full_name" defaultValue={profile.full_name} />    <Text label="Email address" name="email" type="email" defaultValue={profile.email} required={false} readOnly /><Text label="Contact number" name="contact" type="tel" defaultValue={profile.contact} /><Text label="Purok / street address" name="address" defaultValue={profile.address} /><label className="check"><input name="sms_opt_in" type="checkbox" value="1" defaultChecked={Number(profile.sms_opt_in) === 1} /><span>I agree to receive request status updates by SMS</span></label><Button disabled={saving}>{saving ? 'Saving…' : 'Save profile'}</Button></form></section>
  </>;
}

function StaffDashboard({ data, go }) {
  return <><PageHeading eyebrow="STAFF WORKSPACE" title="Operations overview" intro="Review incoming requests, follow incident cases, and keep hearings on schedule." />
    <div className="stats-grid"><StatCard label="Requests to review" value={data?.requests} detail="Submitted or under review" /><StatCard label="Open incident cases" value={data?.incidents} detail="Not resolved or dismissed" /><StatCard label="Upcoming hearings" value={data?.hearings} detail="Scheduled in the future" /></div>
    <div className="quick-links"><Link href="/staff/requests" go={go}>Review document requests <span>→</span></Link><Link href="/staff/incidents" go={go}>Manage incident cases <span>→</span></Link><Link href="/staff/hearings" go={go}>View hearing calendar <span>→</span></Link></div>
  </>;
}

function StaffRequests({ data, saving, onUpdate }) {
  return <><PageHeading eyebrow="STAFF WORKSPACE" title="Document requests" intro="Review resident submissions, record payment, and update each request's progress." />
    {!data?.requests?.length && <Empty />}
    <div className="record-list">{(data?.requests || []).map((item) => <article className="panel record-card" key={item.id}>
      <div className="record-title"><div><p className="eyebrow">{item.reference_no} · {item.service_name}</p><h2>{item.full_name}</h2><p>{item.email} {item.contact ? `· ${item.contact}` : ''}</p></div><Status>{item.status}</Status></div>
      <div className="record-details"><span><b>Purpose</b>{item.purpose}</span><span><b>Fee / payment</b>{money(item.fee)} · {item.payment_status}</span><span><b>Submitted</b>{date(item.created_at)}</span>{item.valid_id_url && <a href={item.valid_id_url}>Download resident ID ↗</a>}</div>
      <form className="staff-update" onSubmit={(event) => { event.preventDefault(); onUpdate(item.id, new FormData(event.currentTarget)); }}>
        <Field label="Status"><select name="status" defaultValue={item.status === 'Submitted' ? 'Under Review' : item.status}><option>Under Review</option><option>Approved</option><option>Rejected</option><option>Ready for Pickup</option><option>Released</option></select></Field>
        <Field label="Staff remarks"><input name="staff_remarks" defaultValue={item.staff_remarks || ''} /></Field>
        {Number(item.fee) > 0 && item.payment_status !== 'Paid' && <label className="check"><input name="payment_action" type="checkbox" value="paid" /><span>Record in-person payment</span></label>}
        <Button disabled={saving}>Save update</Button>
      </form>
    </article>)}</div>
  </>;
}

function StaffIncidents({ data, saving, onUpdate }) {
  return <><PageHeading eyebrow="STAFF WORKSPACE" title="Incident cases" intro="Review reports and provide residents with status updates." />
    {!data?.incidents?.length && <Empty />}
    <div className="record-list">{(data?.incidents || []).map((item) => <article className="panel record-card" key={item.id}>
      <div className="record-title"><div><p className="eyebrow">{item.reference_no} · {item.incident_type}</p><h2>{item.reporter}</h2><p>{item.contact} · {item.incident_date}</p></div><Status>{item.status}</Status></div>
      <div className="record-details"><span><b>Location</b>{item.location}</span><span><b>Description</b>{item.description}</span><span><b>Next hearing</b>{date(item.next_hearing)}</span>{item.evidence_url && <a href={item.evidence_url}>Download evidence ↗</a>}</div>
      <form className="staff-update" onSubmit={(event) => { event.preventDefault(); onUpdate(item.id, new FormData(event.currentTarget)); }}>
        <Field label="Case status"><select name="status" defaultValue={item.status === 'Submitted' ? 'Under Review' : item.status}><option>Under Review</option><option>Hearing Scheduled</option><option>Resolved</option><option>Dismissed</option></select></Field>
        <Field label="Staff remarks"><input name="staff_remarks" defaultValue={item.staff_remarks || ''} /></Field><Button disabled={saving}>Save case update</Button>
      </form>
    </article>)}</div>
  </>;
}

function StaffHearings({ data, saving, onCreate, onUpdate }) {
  return <><PageHeading eyebrow="STAFF WORKSPACE" title="Mediation hearings" intro="Schedule a future hearing for an open case and record its outcome." />
    <section className="panel form-panel"><div className="panel-heading"><div><p className="eyebrow">SCHEDULE</p><h2>New hearing</h2></div></div><form className="form-grid two-col" onSubmit={onCreate}>
      <Select label="Incident case" name="incident_id" items={data?.incidents || []} /><Text label="Date and time" name="scheduled_at" type="datetime-local" /><Text label="Location" name="location" /><Textarea label="Notes for participants" name="notes" required={false} /><Button disabled={saving}>Schedule hearing</Button>
    </form></section>
    <section className="panel"><div className="panel-heading"><div><p className="eyebrow">SCHEDULE</p><h2>Hearings</h2></div></div><div className="record-list compact">{(data?.hearings || []).map((item) => <article className="hearing-row" key={item.id}>
      <div><b>{item.reference_no} · {item.full_name}</b><span>{date(item.scheduled_at)} · {item.location}</span><small>{item.notes || item.outcome || 'No additional notes'}</small></div>
      <form onSubmit={(event) => { event.preventDefault(); onUpdate(item.id, new FormData(event.currentTarget)); }}><select name="status" defaultValue={item.status}><option>Scheduled</option><option>Completed</option><option>Cancelled</option></select><input name="outcome" placeholder="Outcome / note" defaultValue={item.outcome || ''} /><Button disabled={saving}>Update</Button></form>
    </article>)}{!data?.hearings?.length && <Empty>No hearings scheduled.</Empty>}</div></section>
  </>;
}

function ResidentsPage({ data, onSearch }) {
  return <><PageHeading eyebrow="STAFF WORKSPACE" title="Residents" intro="Find resident accounts and verify their contact details." />
    <section className="panel"><form className="search-form" onSubmit={(event) => { event.preventDefault(); onSearch(new FormData(event.currentTarget).get('q')); }}><input name="q" placeholder="Search by name, email, or phone" /><button className="button" type="submit">Search</button></form>
      <DataTable rows={data?.residents} columns={[{ label: 'Name', key: 'full_name' }, { label: 'Email', key: 'email' }, { label: 'Contact', key: 'contact' }, { label: 'Address', key: 'address' }, { label: 'Status', render: (r) => <Status>{Number(r.is_active) ? 'Active' : 'Inactive'}</Status> }, { label: 'Joined', render: (r) => date(r.created_at) }]} />
    </section>
  </>;
}

function ReportsPage({ data }) {
  return <><PageHeading eyebrow="STAFF WORKSPACE" title="Reports" intro="A summary of document activity, incident categories, and recorded payments." />
    <div className="report-grid"><ReportTable title="Requests by status" rows={data?.requests} columns={[['Status', 'status'], ['Total', 'total']]} /><ReportTable title="Incidents by type" rows={data?.incidents} columns={[['Incident type', 'incident_type'], ['Total', 'total']]} /><ReportTable title="Monthly requests" rows={data?.monthly} columns={[['Month', 'month'], ['Total', 'total']]} /><ReportTable title="Payments" rows={data?.payments} columns={[['Provider', 'provider'], ['Status', 'status'], ['Count', 'total'], ['Amount', 'amount']]} /></div>
  </>;
}

function ReportTable({ title, rows, columns }) {
  return <section className="panel"><p className="eyebrow">SUMMARY</p><h2>{title}</h2><DataTable rows={rows} columns={columns.map(([label, key]) => ({ label, key }))} /></section>;
}

function AdminUsers({ data, user, saving, onCreate, onStatus }) {
  return <><PageHeading eyebrow="ADMINISTRATION" title="User management" intro="Create staff accounts and manage access for existing accounts." />
    <section className="panel form-panel"><div className="panel-heading"><div><p className="eyebrow">NEW ACCOUNT</p><h2>Create staff account</h2></div></div><form className="form-grid two-col" onSubmit={onCreate}><Text label="Full name" name="full_name" /><Text label="Email address" name="email" type="email" /><Select label="Staff role" name="role" items={[{ value: 'moderator', label: 'Staff' }, { value: 'admin', label: 'Administrator' }]} /><Text label="Temporary password" name="password" type="password" hint="12–72 characters. Share it securely." /><Button disabled={saving}>Create staff account</Button></form></section>
    <section className="panel"><div className="panel-heading"><div><p className="eyebrow">ACCOUNTS</p><h2>All users</h2></div></div><DataTable rows={data?.users} columns={[
      { label: 'Name', key: 'full_name' }, { label: 'Email', key: 'email' }, { label: 'Role', render: (r) => r.role === 'moderator' ? 'Staff' : r.role }, { label: 'Created', render: (r) => date(r.created_at) },
      { label: 'Access', render: (r) => <button className="text-button" disabled={saving || Number(r.id) === Number(user.id)} onClick={() => onStatus(r.id, Number(r.is_active) ? '0' : '1')}>{Number(r.is_active) ? 'Deactivate' : 'Activate'}</button> }
    ]} /></section>
  </>;
}

function AdminSettings({ data, saving, onSave, onService, onIncidentType }) {
  return <><PageHeading eyebrow="ADMINISTRATION" title="Barangay settings" intro="Keep public contact details, document services, and incident categories up to date." />
    <section className="panel form-panel"><div className="panel-heading"><div><p className="eyebrow">PUBLIC INFORMATION</p><h2>Office details</h2></div></div><form className="form-grid two-col" onSubmit={onSave}>
      <Text label="Barangay name" name="barangay_name" defaultValue={data?.settings?.barangay_name} /><Text label="Office contact" name="office_contact" defaultValue={data?.settings?.office_contact} /><Text label="Office hours" name="office_hours" defaultValue={data?.settings?.office_hours} /><Button disabled={saving}>Save office details</Button>
    </form></section>
    <section className="panel form-panel"><div className="panel-heading"><div><p className="eyebrow">DOCUMENT CATALOG</p><h2>Add a service</h2></div></div><form className="form-grid two-col" onSubmit={(event) => onService(event, null)}>
      <Text label="Service name" name="name" /><Text label="Fee (PHP)" name="fee" type="number" step="0.01" min="0" /><Text label="Description" name="description" required={false} /><Text label="Requirements" name="requirements" required={false} /><input type="hidden" name="is_active" value="1" /><Button disabled={saving}>Add service</Button>
    </form><div className="service-admin-list">{(data?.services || []).map((service) => <form key={service.id} className="service-admin-row" onSubmit={(event) => onService(event, service.id)}>
      <input aria-label="Service name" name="name" defaultValue={service.name} required /><input aria-label="Fee" name="fee" type="number" step="0.01" min="0" defaultValue={service.fee} required /><input aria-label="Description" name="description" defaultValue={service.description || ''} /><input aria-label="Requirements" name="requirements" defaultValue={service.requirements || ''} /><select name="is_active" defaultValue={String(service.is_active)}><option value="1">Active</option><option value="0">Inactive</option></select><Button disabled={saving}>Save</Button>
    </form>)}</div></section>
    <section className="panel form-panel"><div className="panel-heading"><div><p className="eyebrow">INCIDENT CATEGORIES</p><h2>Add an incident type</h2></div></div><form className="inline-form" onSubmit={onIncidentType}><Text label="Category name" name="name" /><Button disabled={saving}>Add category</Button></form><div className="tag-list">{(data?.incident_types || []).map((type) => <span key={type.id}>{type.name} <small>{Number(type.is_active) ? 'Active' : 'Inactive'}</small></span>)}</div></section>
  </>;
}

function TrackPage({ go }) {
  const [result, setResult] = useState(null);
  const [message, setMessage] = useState('');
  async function track(event) {
    event.preventDefault();
    setMessage('');
    setResult(null);
    try {
      const reference = new FormData(event.currentTarget).get('reference_no');
      const response = await api(`/api/track?reference_no=${encodeURIComponent(reference)}`);
      setResult(response.result);
      if (!response.result) setMessage('No matching request or incident was found. Check the reference and try again.');
    } catch (failure) {
      setMessage(failure.message);
    }
  }
  return <section className="panel narrow"><p className="eyebrow">PUBLIC TRACKING</p><h1>Track a request</h1><p>Enter the reference number you received when you submitted a document request or incident report.</p><form className="form-grid" onSubmit={track}><Text label="Reference number" name="reference_no" /><button className="button">Track reference</button></form>
    {message && <div className="alert alert-error">{message}</div>}{result && <div className="track-result"><p className="eyebrow">MATCH FOUND</p><h2>{result.record_type}</h2><p><b>{result.reference_no}</b></p><Status>{result.status}</Status><small>Submitted {date(result.created_at)}</small></div>}
    <p className="form-foot"><Link href="/" go={go}>Return to home</Link></p>
  </section>;
}
